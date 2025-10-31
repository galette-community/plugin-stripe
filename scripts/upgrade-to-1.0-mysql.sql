DROP TABLE galette_stripe_types_cotisation_prices;
ALTER TABLE galette_stripe_history CHANGE COLUMN comment comments varchar(255);
ALTER TABLE galette_stripe_history CHANGE COLUMN metadata request text;
ALTER TABLE galette_stripe_history ADD COLUMN payer_name varchar(255);
