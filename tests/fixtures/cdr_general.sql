-- cdr_general as created by MikoPBX 2026.6.34 (read from a test PBX with .schema).
-- Used by the tests to build a CDR database that matches production.
CREATE TABLE IF NOT EXISTS "cdr_general" (
	`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
	`start` VARCHAR(0) NULL,
	`endtime` VARCHAR(0) NULL,
	`answer` VARCHAR(0) NULL,
	`src_chan` VARCHAR(0) NULL,
	`src_num` VARCHAR(0) NULL,
	`src_name` VARCHAR(0) NULL,
	`dst_chan` VARCHAR(0) NULL,
	`dst_num` VARCHAR(0) NULL,
	`dst_name` VARCHAR(0) NULL,
	`UNIQUEID` VARCHAR(0) NULL,
	`linkedid` VARCHAR(0) NULL,
	`did` VARCHAR(0) NULL,
	`disposition` VARCHAR(0) NULL,
	`recordingfile` VARCHAR(0) NULL,
	`from_account` VARCHAR(0) NULL,
	`to_account` VARCHAR(0) NULL,
	`dialstatus` VARCHAR(0) NULL,
	`appname` VARCHAR(0) NULL,
	`transfer` INTEGER NULL,
	`is_app` VARCHAR(1) NULL,
	`duration` INTEGER NULL,
	`billsec` INTEGER NULL,
	`work_completed` VARCHAR(1) NULL,
	`src_call_id` VARCHAR(0) NULL,
	`dst_call_id` VARCHAR(0) NULL,
	`verbose_call_id` VARCHAR(0) NULL,
	`a_transfer` INTEGER NULL,
	`rec_src_channel` VARCHAR(0) NULL,
	`dtmf_digits` VARCHAR(0) NULL
);
CREATE INDEX "i_cdr_general_UNIQUEID" ON "cdr_general" ("UNIQUEID");
CREATE INDEX "i_cdr_general_start" ON "cdr_general" ("start");
CREATE INDEX "i_cdr_general_src_chan" ON "cdr_general" ("src_chan");
CREATE INDEX "i_cdr_general_dst_chan" ON "cdr_general" ("dst_chan");
CREATE INDEX "i_cdr_general_src_num" ON "cdr_general" ("src_num");
CREATE INDEX "i_cdr_general_dst_num" ON "cdr_general" ("dst_num");
CREATE INDEX "i_cdr_general_linkedid" ON "cdr_general" ("linkedid");
