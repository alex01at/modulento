-- Per-seat plans (price_cents is then the price per seat per period).
-- min_quantity is the floor an account is always billed for, even with
-- fewer real seats in use - set by the operator per plan.
ALTER TABLE subscription_plan
    ADD COLUMN per_seat TINYINT(1) NOT NULL DEFAULT 0 AFTER max_images_per_offer,
    ADD COLUMN min_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER per_seat;

-- quantity is the live, currently billed seat count. stripe_item_ref is the
-- Stripe subscription *item* id - quantity lives there in Stripe's model,
-- not on the subscription itself, so it is captured separately from
-- provider_ref (the subscription id).
ALTER TABLE subscription
    ADD COLUMN quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER provider_ref,
    ADD COLUMN stripe_item_ref VARCHAR(255) NULL AFTER quantity;

-- The quantity an order was placed for, so its amount can be explained and
-- a renewal or invoice can still show it after the live quantity moves on.
ALTER TABLE subscription_order
    ADD COLUMN quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER plan_id;
