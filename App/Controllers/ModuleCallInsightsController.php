<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\App\Controllers;

use InvalidArgumentException;
use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\AdminCabinet\Providers\AssetProvider;
use MikoPBX\Common\Providers\JwtProvider;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleCallInsights\Lib\AccessPolicy;
use Modules\ModuleCallInsights\Lib\CdrReader;
use Modules\ModuleCallInsights\Lib\IndexStore;
use Modules\ModuleCallInsights\Lib\Labels;
use Modules\ModuleCallInsights\Lib\QueryService;
use PDO;
use Phalcon\Mvc\Dispatcher;
use Throwable;

/**
 * Admin-cabinet page and JSON actions for Call Insights.
 *
 * Every request is checked for the admins role here, on top of the core's
 * SecurityPlugin (plan rule 9). The core's beforeExecuteRoute() returns
 * void, so a refused request is forwarded to deniedAction() rather than
 * stopped by returning false.
 *
 * The JSON actions are read-only GETs. They read the module database and
 * the CDR over read-only connections, and build all SQL in QueryService
 * from whitelists. CDR text (caller names and numbers) is returned as
 * data; the page renders it as text only.
 */
class ModuleCallInsightsController extends BaseController
{
    private const string MODULE_ID = 'ModuleCallInsights';
    private const string DENIED_ACTION = 'denied';

    /** Leg fields a drill-down shows. recordingfile (a server path) is never sent. */
    private const array LEG_FIELDS = [
        'id', 'start', 'answer', 'endtime', 'src_num', 'src_name', 'dst_num', 'dst_name', 'dst_chan',
        'disposition', 'billsec', 'duration', 'dtmf_digits',
    ];

    public function beforeExecuteRoute(Dispatcher $dispatcher): void
    {
        parent::beforeExecuteRoute($dispatcher);

        if ($dispatcher->getActionName() === self::DENIED_ACTION) {
            return;
        }
        if (!AccessPolicy::isAllowedRole($this->currentRole())) {
            $dispatcher->forward(['action' => self::DENIED_ACTION]);
        }
    }

    /** The dashboard page. CDR data never goes through Volt; the page loads it as JSON. */
    public function indexAction(): void
    {
        // Semantic UI parts the core loads only on some pages: the KPI tiles
        // (statistic) and the drill-down (modal).
        $this->assets->collection(AssetProvider::SEMANTIC_UI_CSS)
            ->addCss('css/vendor/semantic/statistic.min.css', true)
            ->addCss('css/vendor/semantic/modal.min.css', true);
        $this->assets->collection(AssetProvider::SEMANTIC_UI_JS)
            ->addJs('js/vendor/semantic/modal.min.js', true);

        // Served from this module's public/assets folder; ECharts only on this page.
        $this->assets->collection(AssetProvider::HEADER_CSS)
            ->addCss('css/cache/' . self::MODULE_ID . '/call-insights.css', true);
        $this->assets->collection(AssetProvider::FOOTER_JS)
            ->addJs('js/cache/' . self::MODULE_ID . '/vendor/echarts.min.js', true)
            ->addJs('js/cache/' . self::MODULE_ID . '/call-insights.js', true);
    }

    /**
     * GET query: metric, from, to, interval, breakdown, filters[name][]=value, compare=1.
     */
    public function queryAction(): void
    {
        $this->json(function (): array {
            $q = $this->queryParams();
            $service = $this->queryService();
            $result = ['current' => $service->query($q)];
            if ($this->request->getQuery('compare') === '1') {
                [$from, $to] = QueryService::previousPeriod($q['from'], $q['to']);
                $result['previous'] = $service->query(['from' => $from, 'to' => $to] + $q);
            }
            return $result;
        });
    }

    /**
     * GET calls: from, to, filters, page. One page of calls, each with its legs
     * read from the CDR by linkedid.
     */
    public function callsAction(): void
    {
        $this->json(function (): array {
            $q = $this->queryParams();
            $page = $this->queryService()->calls($q, max(1, (int)$this->request->getQuery('page', 'int', 1)));

            $legs = [];
            $linkedIds = array_column($page['calls'], 'linkedid');
            if ($linkedIds !== []) {
                foreach ((new CdrReader($this->cdrFile()))->legsOfCalls($linkedIds) as $leg) {
                    $shown = array_intersect_key($leg, array_flip(self::LEG_FIELDS));
                    $shown['has_recording'] = (string)$leg['recordingfile'] !== '';
                    $legs[(string)$leg['linkedid']][] = $shown;
                }
            }
            foreach ($page['calls'] as &$call) {
                $call['legs'] = $legs[$call['linkedid']] ?? [];
            }
            unset($call);

            return $page;
        });
    }

    /** GET labels: names of extensions, queues and providers. */
    public function labelsAction(): void
    {
        $this->json(fn (): array => Labels::load($this->configFile()));
    }

    /** GET status: data freshness and back-fill progress. */
    public function statusAction(): void
    {
        $this->json(function (): array {
            $db = $this->moduleDb();
            $state = $db->query('SELECT name, value FROM ' . IndexStore::STATE)->fetchAll(PDO::FETCH_KEY_PAIR);
            $range = $db->query('SELECT MIN(local_date), MAX(local_date), COUNT(*) FROM ' . IndexStore::CALLS)->fetch(PDO::FETCH_NUM);

            return [
                'last_run_at'   => $state[IndexStore::LAST_RUN_AT] ?? null,
                'backfill_done' => isset($state[IndexStore::BACKFILL_CURSOR]) && (int)$state[IndexStore::BACKFILL_CURSOR] <= 0,
                'oldest_day'    => $range[0],
                'newest_day'    => $range[1],
                'calls'         => (int)$range[2],
                'service_level_seconds' => $this->serviceLevelSeconds($db),
            ];
        });
    }

    /**
     * 403 for anyone who is not an admin: JSON for AJAX, a page otherwise.
     */
    public function deniedAction(): void
    {
        $this->response->setStatusCode(403, 'Forbidden');
        if ($this->request->isAjax()) {
            $this->view->raw_response = json_encode([
                'success' => false,
                'reload'  => false,
                'message' => ['error' => $this->translation->_('ci_AccessDenied')],
            ]);
        }
    }

    /**
     * Runs $build and sends its result as JSON. Bad requests get 400 with a
     * short reason; anything else gets a generic 500 and a log entry.
     */
    private function json(callable $build): void
    {
        $status = 200;
        try {
            if (!$this->request->isGet() || !$this->request->isAjax()) {
                throw new InvalidArgumentException('Use an AJAX GET request.');
            }
            $body = ['success' => true, 'data' => $build()];
        } catch (InvalidArgumentException $e) {
            $status = 400;
            $body = ['success' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            $status = 500;
            $body = ['success' => false, 'message' => 'Call Insights could not load this data.'];
            SystemMessages::sysLogMsg(self::class, 'Request failed: ' . $e->getMessage(), LOG_ERR);
        }
        $this->response->setStatusCode($status);
        $this->view->raw_response = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * The query parameters QueryService understands, taken from the request
     * as plain values. QueryService validates every one of them.
     *
     * @return array<string, mixed>
     */
    private function queryParams(): array
    {
        $get = $this->request->getQuery();
        $filters = $get['filters'] ?? [];

        return [
            'metric'    => (string)($get['metric'] ?? 'calls'),
            'from'      => (string)($get['from'] ?? ''),
            'to'        => (string)($get['to'] ?? ''),
            'interval'  => (string)($get['interval'] ?? 'day'),
            'breakdown' => isset($get['breakdown']) ? (string)$get['breakdown'] : null,
            'filters'   => is_array($filters) ? $filters : throw new InvalidArgumentException('Filters must be a map of lists.'),
        ];
    }

    private function queryService(): QueryService
    {
        $db = $this->moduleDb();

        return new QueryService($db, $this->serviceLevelSeconds($db));
    }

    private function serviceLevelSeconds(PDO $db): int
    {
        $value = $db->query('SELECT service_level_seconds FROM m_Settings ORDER BY id LIMIT 1')->fetchColumn();

        return $value === false || (int)$value < 1 ? 20 : (int)$value;
    }

    private function moduleDb(): PDO
    {
        return CdrReader::openReadOnly(PbxExtensionUtils::getModuleDir(self::MODULE_ID) . '/db/module.db');
    }

    private function cdrFile(): string
    {
        return (string)$this->di->getShared('config')->path('cdrDatabase.dbfile');
    }

    private function configFile(): string
    {
        return (string)$this->di->getShared('config')->path('database.dbfile');
    }

    /**
     * The caller's role, resolved the same way as the core's SecurityPlugin:
     * the Bearer token first, then the refresh-token cookie of a page load.
     */
    private function currentRole(): ?string
    {
        $jwt = $this->di->getShared(JwtProvider::SERVICE_NAME);

        $role = $jwt->extractRoleFromHeader($this->request->getHeader('Authorization'));
        if ($role !== null) {
            return $role;
        }

        if ($this->cookies->has('refreshToken')) {
            try {
                $refreshToken = $this->cookies->get('refreshToken')->getValue();
                if (!empty($refreshToken)) {
                    return $jwt->extractRoleFromRefreshToken($refreshToken);
                }
            } catch (Throwable) {
                // An unreadable cookie counts as no role: refused.
            }
        }

        return null;
    }
}
