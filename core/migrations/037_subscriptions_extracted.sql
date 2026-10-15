-- Subscriptions moved out of the core into an addon of their own
-- (modulento-ext-abos, Registrar::subscriptionProvider()). These tables
-- were core's own (migrations 022, 023, 024, 031, 033); the addon creates
-- its own from a clean slate. Any plan, subscription, order or invoice
-- this installation already had is gone with this migration - install the
-- addon and set plans up again.

DROP TABLE IF EXISTS subscription_order;
DROP TABLE IF EXISTS subscription;
DROP TABLE IF EXISTS subscription_invoice;
DROP TABLE IF EXISTS subscription_billing_address;
DROP TABLE IF EXISTS subscription_plan;
