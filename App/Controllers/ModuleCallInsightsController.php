<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\App\Controllers;

use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\Common\Providers\JwtProvider;
use Modules\ModuleCallInsights\Lib\AccessPolicy;
use Phalcon\Mvc\Dispatcher;
use Throwable;

/**
 * Admin-cabinet pages and JSON actions for Call Insights.
 *
 * Every request is checked for the admins role here, on top of the core's
 * SecurityPlugin (plan rule 9). The core's beforeExecuteRoute() returns
 * void, so a refused request is forwarded to deniedAction() rather than
 * stopped by returning false.
 */
class ModuleCallInsightsController extends BaseController
{
    private const string DENIED_ACTION = 'denied';

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

    /**
     * Dashboard shell. Charts arrive in phase 2; CDR data never goes
     * through Volt, the page will fetch it as JSON.
     */
    public function indexAction(): void
    {
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
