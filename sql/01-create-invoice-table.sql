DROP TABLE IF EXISTS `wp_verena_invoice`;
CREATE TABLE IF NOT EXISTS `wp_verena_invoice` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `member_id` bigint(20) NOT NULL,
  `client_id` bigint(20) NOT NULL,
  `consultation_id` bigint(20) NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `consultation_details` text COLLATE utf8mb4_unicode_ci,
  `invoice_status` int(11) NOT NULL,
  `price` int(11) NOT NULL,
  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
COMMIT;