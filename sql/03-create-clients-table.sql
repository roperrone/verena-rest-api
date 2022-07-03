DROP TABLE IF EXISTS `wp_verena_clients`;
CREATE TABLE IF NOT EXISTS `wp_verena_clients` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `firstname` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lastname` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` tinytext NOT NULL,
  `additional_infos` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint(20),
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
COMMIT;