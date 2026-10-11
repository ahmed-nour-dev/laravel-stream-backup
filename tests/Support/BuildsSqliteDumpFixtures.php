<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Support;

/**
 * A `sqlite3 .dump`-shaped fixture used by the SQLite restore tests.
 */
trait BuildsSqliteDumpFixtures
{
    protected function sqliteDump(): string
    {
        return <<<'SQL'
PRAGMA foreign_keys=OFF;
BEGIN TRANSACTION;
CREATE TABLE sbr_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, note TEXT);
INSERT INTO sbr_users VALUES(1,'alice',NULL);
INSERT INTO sbr_users VALUES(2,'bob','line one'||char(10)||'line two; -- not a comment');
INSERT INTO sbr_users VALUES(5,'it''s "quoted"','multi
line');
CREATE TABLE sbr_posts (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES sbr_users(id),
    body TEXT
);
INSERT INTO sbr_posts VALUES(10,1,'hello');
INSERT INTO sbr_posts VALUES(11,2,'world');
DELETE FROM "sqlite_sequence";
INSERT INTO "sqlite_sequence" VALUES('sbr_users',40);
CREATE INDEX sbr_posts_user_idx ON sbr_posts(user_id);
CREATE TRIGGER sbr_users_touch AFTER INSERT ON sbr_users
BEGIN
    UPDATE sbr_posts SET body = CASE WHEN new.id > 0 THEN body ELSE 'x' END WHERE 0;
    UPDATE sbr_posts SET body = body WHERE 0;
END;
CREATE VIEW sbr_post_view AS SELECT id, body FROM sbr_posts;
COMMIT;

SQL;
    }
}
