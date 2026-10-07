-- What "fast responder" is measured from: no comparable figure exists yet
-- anywhere in the core, so it is kept here, recomputed periodically
-- (Provider\Badges::recomputeResponseTimes(), task core.badges-recompute)
-- rather than read live on every page view. "Top rated" needs no column of
-- its own - it reads the rating_count/rating_sum a provider already has.
ALTER TABLE provider
    ADD COLUMN avg_response_minutes INT UNSIGNED NULL,
    ADD COLUMN response_sample_count INT UNSIGNED NOT NULL DEFAULT 0;
