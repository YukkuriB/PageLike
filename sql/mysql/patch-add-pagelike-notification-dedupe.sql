CREATE TABLE /*_*/pagelike_notification_dedupe (
  plnd_page_id INT UNSIGNED NOT NULL,
  plnd_user_id INT UNSIGNED NOT NULL,
  INDEX pagelike_notification_dedupe_user_page (
    plnd_user_id, plnd_page_id
  ),
  PRIMARY KEY(plnd_page_id, plnd_user_id)
) /*$wgDBTableOptions*/;
