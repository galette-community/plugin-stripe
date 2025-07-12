--
-- Table structure for table `galette_stripe_history`
--
DROP TABLE IF EXISTS galette_stripe_history;
CREATE TABLE galette_stripe_history (
  id_stripe int(11) NOT NULL auto_increment,
  history_date datetime NOT NULL,
  intent_id varchar(255) COLLATE utf8_unicode_ci,
  amount double NOT NULL,
  comment varchar(255)  COLLATE utf8_unicode_ci,
  metadata text COLLATE utf8_unicode_ci,
  state tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_stripe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Table structure for table `galette_stripe_preferences`
--
DROP TABLE IF EXISTS galette_stripe_preferences;
CREATE TABLE galette_stripe_preferences (
  id_pref int(10) unsigned NOT NULL auto_increment,
  nom_pref varchar(100) NOT NULL default '',
  val_pref varchar(200) NOT NULL default '',
  PRIMARY KEY (id_pref),
  UNIQUE KEY (nom_pref)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci;

INSERT INTO galette_stripe_preferences (nom_pref, val_pref) VALUES ('stripe_pubkey', '');
INSERT INTO galette_stripe_preferences (nom_pref, val_pref) VALUES ('stripe_privkey', '');
INSERT INTO galette_stripe_preferences (nom_pref, val_pref) VALUES ('stripe_webhook_secret', '');
INSERT INTO galette_stripe_preferences (nom_pref, val_pref) VALUES ('stripe_inactives', '4,6,7');
INSERT INTO galette_stripe_preferences (nom_pref, val_pref) VALUES ('stripe_country', 'FR');
INSERT INTO galette_stripe_preferences (nom_pref, val_pref) VALUES ('stripe_currency', 'eur');
