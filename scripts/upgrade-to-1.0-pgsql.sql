DROP TABLE galette_stripe_types_cotisation_prices;
ALTER TABLE galette_stripe_history RENAME COLUMN metadata TO request;
ALTER TABLE galette_stripe_history ADD COLUMN payer_name varchar(255);
