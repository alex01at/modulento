-- How many offers of each type a plan allows (JSON {"type.id": count}, a
-- missing type means none) and how many pictures per offer (NULL: no limit).
ALTER TABLE subscription_plan
    ADD COLUMN offer_limits TEXT NOT NULL DEFAULT '{}' AFTER features,
    ADD COLUMN max_images_per_offer SMALLINT UNSIGNED NULL AFTER offer_limits;
