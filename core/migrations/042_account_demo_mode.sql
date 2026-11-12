-- An administrator can put an account into demo mode: it can still log in
-- and look at everything, but every POST request - the only way this core
-- ever writes anything - is refused for it, enforced centrally in
-- Support\Router::dispatch(), not by each controller remembering to check.
ALTER TABLE account ADD COLUMN demo_mode TINYINT(1) NOT NULL DEFAULT 0;
