-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1:3306
-- Généré le : mar. 31 mars 2026 à 17:14
-- Version du serveur : 8.3.0
-- Version de PHP : 8.2.18

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `widemaze`
--

-- --------------------------------------------------------

--
-- Structure de la table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Journal d''activité système';

--
-- Déchargement des données de la table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `action`, `details`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, NULL, 'login_failed', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-25 19:10:01'),
(2, NULL, 'login_failed', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-25 19:10:42'),
(3, 1, 'register', '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-25 19:12:59'),
(4, 1, 'login_success', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-25 19:13:15'),
(5, 1, 'post_created', '{\"post_id\":\"1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-25 19:14:45'),
(6, 2, 'register', '{\"ip\":\"127.0.0.1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-25 19:18:13'),
(7, 2, 'login_success', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-25 19:18:24'),
(8, 2, 'friend_request_sent', '{\"target_id\":1}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-25 19:18:28'),
(9, 2, 'login_success', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-25 19:20:49'),
(10, 2, 'logout', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-25 19:21:24'),
(11, 1, 'login_success', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-25 19:21:32'),
(12, 1, 'community_created', '{\"community_id\":\"1\",\"name\":\"Les Graphistes\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-25 19:23:21'),
(13, 2, 'community_joined', '{\"community_id\":1}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-25 19:23:43'),
(14, 2, 'post_created', '{\"post_id\":\"2\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-25 19:25:48'),
(15, 2, 'post_created', '{\"post_id\":\"3\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-25 19:25:51'),
(16, 1, 'login_success', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-25 20:40:24'),
(17, 2, 'login_success', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-25 20:41:17'),
(18, 1, 'logout', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-25 20:42:34'),
(19, NULL, 'login_failed', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-25 20:43:06'),
(20, 1, 'login_success', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-25 20:43:39'),
(21, 1, 'login_success', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-28 14:31:02'),
(22, 1, 'logout', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-28 14:31:18'),
(23, 2, 'login_success', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-28 14:31:30'),
(24, 1, 'login_success', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-28 14:32:44'),
(25, 1, 'logout', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-28 14:46:55'),
(26, 3, 'register', '{\"ip\":\"::1\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-28 14:49:53'),
(27, 3, 'login_success', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-28 14:50:21'),
(28, 3, 'friend_request_sent', '{\"target_id\":2}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-28 14:51:54'),
(29, 3, 'friend_request_sent', '{\"target_id\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-28 14:51:58'),
(30, 3, 'community_joined', '{\"community_id\":1}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-28 14:52:26'),
(31, 3, 'post_created', '{\"post_id\":\"4\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-28 14:52:43'),
(32, 2, 'logout', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-28 14:57:22'),
(33, 1, 'login_success', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-28 14:57:34'),
(34, 1, 'login_failed', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-29 19:23:19'),
(35, 1, 'login_failed', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-29 19:23:29'),
(36, 1, 'login_failed', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-29 19:23:57'),
(37, 2, 'login_success', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-29 19:24:45'),
(38, 2, 'logout', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-29 19:26:21'),
(39, 1, 'login_success', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-29 19:26:32'),
(40, 1, 'admin_announcement', '{\"title\":\"Annonce Special\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-29 19:30:26'),
(41, 3, 'login_failed', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-29 19:33:37'),
(42, 3, 'login_success', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-29 19:33:48'),
(43, 3, 'logout', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-29 19:33:52'),
(44, 2, 'login_success', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-29 19:34:03'),
(45, 1, 'post_created', '{\"post_id\":\"5\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-29 19:37:03'),
(46, 1, 'post_created', '{\"post_id\":\"6\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-29 19:37:04'),
(47, 2, 'logout', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-29 19:53:31'),
(48, 1, 'login_success', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-29 19:53:51'),
(49, 1, 'update_avatar', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-29 19:54:21'),
(50, 2, 'login_success', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-29 19:55:02'),
(51, 2, 'logout', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-29 19:55:06'),
(52, 2, 'login_success', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-29 19:55:28'),
(53, 2, 'login_success', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36', '2026-03-30 00:16:01'),
(54, 1, 'login_success', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-30 00:17:29'),
(55, 2, 'login_success', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.7444.175 Safari/537.36', '2026-03-30 21:02:13'),
(56, 2, 'post_created', '{\"post_id\":\"7\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.7444.175 Safari/537.36', '2026-03-30 21:28:12'),
(57, 1, 'login_success', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 OPR/128.0.0.0', '2026-03-31 07:55:38');

-- --------------------------------------------------------

--
-- Structure de la table `ami`
--

DROP TABLE IF EXISTS `ami`;
CREATE TABLE IF NOT EXISTS `ami` (
  `id_relation` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `id` int UNSIGNED NOT NULL,
  `idami` int UNSIGNED NOT NULL,
  `demandeami` tinyint DEFAULT '0',
  `accepterami` tinyint DEFAULT '0',
  `date_demande` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_acceptation` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_relation`),
  UNIQUE KEY `unique_friendship` (`id`,`idami`),
  KEY `idx_id` (`id`),
  KEY `idx_idami` (`idami`),
  KEY `idx_status` (`accepterami`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Relations d''amitié et demandes';

--
-- Déchargement des données de la table `ami`
--

INSERT INTO `ami` (`id_relation`, `id`, `idami`, `demandeami`, `accepterami`, `date_demande`, `date_acceptation`, `created_at`) VALUES
(1, 2, 1, 1, 0, '2026-03-25 19:18:28', NULL, '2026-03-25 11:18:28'),
(2, 3, 2, 1, 0, '2026-03-28 14:51:54', NULL, '2026-03-28 06:51:54'),
(3, 3, 1, 1, 0, '2026-03-28 14:51:58', NULL, '2026-03-28 06:51:58');

-- --------------------------------------------------------

--
-- Structure de la table `communautes`
--

DROP TABLE IF EXISTS `communautes`;
CREATE TABLE IF NOT EXISTS `communautes` (
  `id_communaute` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `image_couverture` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `categorie` enum('Academic','Club','Social','Sports','Arts','Tech','Career') COLLATE utf8mb4_unicode_ci DEFAULT 'Academic',
  `id_createur` int UNSIGNED NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `date_creation` datetime DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_communaute`),
  KEY `idx_categorie` (`categorie`),
  KEY `id_createur` (`id_createur`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Communautés académiques';

--
-- Déchargement des données de la table `communautes`
--

INSERT INTO `communautes` (`id_communaute`, `nom`, `description`, `image_couverture`, `categorie`, `id_createur`, `is_active`, `date_creation`, `created_at`, `updated_at`) VALUES
(1, 'Les Graphistes', 'ici C\'est le graphic design et rien d\'autre', NULL, 'Academic', 1, 1, '2026-03-25 19:23:21', '2026-03-25 11:23:21', '2026-03-25 11:23:21');

-- --------------------------------------------------------

--
-- Structure de la table `communaute_membres`
--

DROP TABLE IF EXISTS `communaute_membres`;
CREATE TABLE IF NOT EXISTS `communaute_membres` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_communaute` int UNSIGNED NOT NULL,
  `id_utilisateur` int UNSIGNED NOT NULL,
  `role` enum('admin','moderator','member') COLLATE utf8mb4_unicode_ci DEFAULT 'member',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_member` (`id_communaute`,`id_utilisateur`),
  KEY `id_utilisateur` (`id_utilisateur`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Membres des communautés';

--
-- Déchargement des données de la table `communaute_membres`
--

INSERT INTO `communaute_membres` (`id`, `id_communaute`, `id_utilisateur`, `role`, `created_at`) VALUES
(1, 1, 1, 'admin', '2026-03-25 19:23:21'),
(2, 1, 2, 'member', '2026-03-25 19:23:43'),
(3, 1, 3, 'member', '2026-03-28 14:52:26');

-- --------------------------------------------------------

--
-- Structure de la table `community_events`
--

DROP TABLE IF EXISTS `community_events`;
CREATE TABLE IF NOT EXISTS `community_events` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `community_id` int UNSIGNED NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `event_date` datetime NOT NULL,
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `max_participants` int UNSIGNED DEFAULT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_community` (`community_id`),
  KEY `idx_date` (`event_date`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Événements organisés par les communautés';

-- --------------------------------------------------------

--
-- Structure de la table `community_resources`
--

DROP TABLE IF EXISTS `community_resources`;
CREATE TABLE IF NOT EXISTS `community_resources` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `community_id` int UNSIGNED NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `file_url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` int UNSIGNED DEFAULT NULL,
  `uploaded_by` int UNSIGNED NOT NULL,
  `downloads` int UNSIGNED DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_community` (`community_id`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  KEY `idx_downloads` (`downloads`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ressources partagées dans les communautés';

-- --------------------------------------------------------

--
-- Structure de la table `event_participants`
--

DROP TABLE IF EXISTS `event_participants`;
CREATE TABLE IF NOT EXISTS `event_participants` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `status` enum('going','interested','not_going') COLLATE utf8mb4_unicode_ci DEFAULT 'going',
  `registered_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_participant` (`event_id`,`user_id`),
  KEY `idx_event` (`event_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Participants aux événements';

-- --------------------------------------------------------

--
-- Structure de la table `message`
--

DROP TABLE IF EXISTS `message`;
CREATE TABLE IF NOT EXISTS `message` (
  `idmessage` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_expediteur` int UNSIGNED NOT NULL,
  `id_destinataire` int UNSIGNED NOT NULL,
  `textemessage` text COLLATE utf8mb4_unicode_ci,
  `type` enum('text','image','video','audio','document','voice') COLLATE utf8mb4_unicode_ci DEFAULT 'text',
  `file_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint UNSIGNED DEFAULT NULL,
  `file_duration` int UNSIGNED DEFAULT NULL,
  `lu` tinyint DEFAULT '0',
  `deleted_for_sender` tinyint DEFAULT '0',
  `deleted_for_receiver` tinyint DEFAULT '0',
  `file_metadata` text COLLATE utf8mb4_unicode_ci,
  `datemessage` datetime DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`idmessage`),
  KEY `idx_expediteur` (`id_expediteur`),
  KEY `idx_destinataire` (`id_destinataire`),
  KEY `idx_lu` (`lu`),
  KEY `idx_type` (`type`),
  KEY `idx_conversation` (`id_expediteur`,`id_destinataire`),
  KEY `idx_date` (`datemessage`),
  KEY `idx_unread` (`id_destinataire`,`lu`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Messages privés entre utilisateurs';

--
-- Déchargement des données de la table `message`
--

INSERT INTO `message` (`idmessage`, `id_expediteur`, `id_destinataire`, `textemessage`, `type`, `file_url`, `file_name`, `file_type`, `file_size`, `file_duration`, `lu`, `deleted_for_sender`, `deleted_for_receiver`, `file_metadata`, `datemessage`, `created_at`) VALUES
(1, 1, 1, 'bonjour mon frere', 'text', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, NULL, '2026-03-25 19:15:21', '2026-03-25 11:15:21'),
(2, 3, 3, 'Salut monsieur', 'text', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, NULL, '2026-03-28 14:50:58', '2026-03-28 06:50:58'),
(3, 3, 3, '😁', 'text', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, NULL, '2026-03-28 14:51:16', '2026-03-28 06:51:16'),
(4, 1, 1, '', 'voice', 'C:\\wamp64\\www\\widemaze/uploads/messages/69c77c0d0d1c5_voice_6bd5f1ec.webm', 'Note vocale.webm', NULL, 87234, 6, 0, 0, 0, NULL, '2026-03-28 14:58:21', '2026-03-28 06:58:21'),
(5, 1, 1, '', 'voice', 'uploads/messages/69c77c324090c_voice_187b899e.webm', 'Note vocale.webm', 'audio', 105588, 6, 0, 0, 0, NULL, '2026-03-28 14:58:58', '2026-03-28 06:58:58'),
(6, 1, 1, 'mon frere', 'text', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, NULL, '2026-03-28 14:59:35', '2026-03-28 06:59:35'),
(7, 2, 2, 'salut', 'text', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, NULL, '2026-03-29 19:25:41', '2026-03-29 11:25:41'),
(8, 2, 2, '😃', 'text', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, NULL, '2026-03-29 19:26:05', '2026-03-29 11:26:05'),
(9, 1, 2, 'Salut David comment vas tu?', 'text', NULL, NULL, NULL, NULL, NULL, 1, 0, 0, NULL, '2026-03-29 19:32:29', '2026-03-29 11:32:29'),
(10, 2, 1, 'Salut DAVID, Ça va et toi ? I\'m very good.', 'text', NULL, NULL, NULL, NULL, NULL, 1, 0, 0, NULL, '2026-03-29 19:40:28', '2026-03-29 11:40:28'),
(11, 1, 2, 'tu etudies quoi?', 'text', NULL, NULL, NULL, NULL, NULL, 1, 0, 0, NULL, '2026-03-29 19:41:51', '2026-03-29 11:41:51'),
(12, 2, 1, 'Moi, rien de spécial ?', 'text', NULL, NULL, NULL, NULL, NULL, 1, 0, 0, NULL, '2026-03-29 19:42:24', '2026-03-29 11:42:24'),
(13, 2, 1, 'http://localhost/widemaze/pages/profil.php?post=6', 'text', NULL, NULL, NULL, NULL, NULL, 1, 0, 0, NULL, '2026-03-29 19:45:20', '2026-03-29 11:45:20');

-- --------------------------------------------------------

--
-- Structure de la table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text COLLATE utf8mb4_unicode_ci,
  `actor_id` int UNSIGNED DEFAULT NULL,
  `link` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_id` int DEFAULT NULL,
  `is_read` tinyint DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_user_unread` (`user_id`,`is_read`),
  KEY `idx_type` (`type`),
  KEY `idx_date` (`created_at`),
  KEY `notifications_ibfk_2` (`actor_id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Notifications utilisateur';

--
-- Déchargement des données de la table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `title`, `content`, `actor_id`, `link`, `item_id`, `is_read`, `created_at`, `read_at`) VALUES
(1, 1, 'friend_request', '@wings_dbn vous a envoyé une demande d\'ami', NULL, 2, 'http://localhost/widemaze/pages/notifications.php', NULL, 1, '2026-03-25 19:18:28', '2026-03-29 19:31:07'),
(2, 1, 'like', '@wings_dbn a aimé votre publication', NULL, 2, 'http://localhost/widemaze/index.php?post=1', NULL, 1, '2026-03-25 19:19:19', '2026-03-29 19:57:00'),
(3, 1, 'community_join', '@wings_dbn a rejoint votre communauté', NULL, 2, 'http://localhost/widemaze/pages/communaute.php?id=1', NULL, 1, '2026-03-25 19:23:43', '2026-03-29 19:43:07'),
(4, 1, 'community_post', 'Nouvelle publication dans une communauté que vous suivez', NULL, 2, 'http://localhost/widemaze/pages/communaute.php?id=1', NULL, 1, '2026-03-25 19:25:48', '2026-03-29 19:43:05'),
(5, 1, 'community_post', 'Nouvelle publication dans une communauté que vous suivez', NULL, 2, 'http://localhost/widemaze/pages/communaute.php?id=1', NULL, 1, '2026-03-25 19:25:51', '2026-03-29 19:43:03'),
(6, 2, 'friend_request', '@pokouma vous a envoyé une demande d\'ami', NULL, 3, 'http://localhost/widemaze/pages/notifications.php', NULL, 1, '2026-03-28 14:51:54', '2026-03-30 00:37:44'),
(7, 1, 'friend_request', '@pokouma vous a envoyé une demande d\'ami', NULL, 3, 'http://localhost/widemaze/pages/notifications.php', NULL, 1, '2026-03-28 14:51:58', '2026-03-29 19:40:56'),
(8, 1, 'community_join', '@pokouma a rejoint votre communauté', NULL, 3, 'http://localhost/widemaze/pages/communaute.php?id=1', NULL, 1, '2026-03-28 14:52:26', '2026-03-29 19:40:55'),
(9, 1, 'community_post', 'Nouvelle publication dans une communauté que vous suivez', NULL, 3, 'http://localhost/widemaze/pages/communaute.php?id=1', NULL, 0, '2026-03-28 14:52:43', '2026-03-29 19:40:45'),
(10, 2, 'community_post', 'Nouvelle publication dans une communauté que vous suivez', NULL, 3, 'http://localhost/widemaze/pages/communaute.php?id=1', NULL, 1, '2026-03-28 14:52:43', '2026-03-30 00:37:44'),
(11, 1, 'announcement', 'Annonce Special', 'Bonjour a tous\r\nNous avons le plaisir de vous annoncer que demain nous avons Conference, sur les Intelligences Artificielles de nos jours.\r\nPour plus d\'information veuiller aller visiter la page officiel de #Wuhan University of Technology', NULL, NULL, NULL, 0, '2026-03-29 19:30:26', '2026-03-29 19:40:44'),
(12, 2, 'announcement', 'Annonce Special', 'Bonjour a tous\r\nNous avons le plaisir de vous annoncer que demain nous avons Conference, sur les Intelligences Artificielles de nos jours.\r\nPour plus d\'information veuiller aller visiter la page officiel de #Wuhan University of Technology', NULL, NULL, NULL, 1, '2026-03-29 19:30:26', '2026-03-30 00:37:44'),
(13, 3, 'announcement', 'Annonce Special', 'Bonjour a tous\r\nNous avons le plaisir de vous annoncer que demain nous avons Conference, sur les Intelligences Artificielles de nos jours.\r\nPour plus d\'information veuiller aller visiter la page officiel de #Wuhan University of Technology', NULL, NULL, NULL, 0, '2026-03-29 19:30:26', NULL),
(14, 2, 'message', 'Nouveau message de @wings_dbn_officiel', NULL, 1, 'messagerie.php?user=1', NULL, 1, '2026-03-29 19:32:29', '2026-03-30 00:37:44'),
(15, 1, 'message', 'Nouveau message de @wings_dbn', NULL, 2, 'messagerie.php?user=2', NULL, 0, '2026-03-29 19:40:28', '2026-03-29 19:43:11'),
(16, 2, 'message', 'Nouveau message de @wings_dbn_officiel', NULL, 1, 'messagerie.php?user=1', NULL, 1, '2026-03-29 19:41:51', '2026-03-30 00:37:44'),
(17, 1, 'message', 'Nouveau message de @wings_dbn', NULL, 2, 'messagerie.php?user=2', NULL, 0, '2026-03-29 19:42:24', NULL),
(18, 1, 'message', 'Nouveau message de @wings_dbn', NULL, 2, 'messagerie.php?user=2', NULL, 0, '2026-03-29 19:45:20', NULL);

-- --------------------------------------------------------

--
-- Structure de la table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint DEFAULT '0',
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_token` (`token`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_token` (`token`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tokens de réinitialisation de mot de passe';

-- --------------------------------------------------------

--
-- Structure de la table `password_reset_attempts`
--

DROP TABLE IF EXISTS `password_reset_attempts`;
CREATE TABLE IF NOT EXISTS `password_reset_attempts` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `success` tinyint DEFAULT '0',
  `attempted_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip` (`ip_address`),
  KEY `idx_date` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tentatives de récupération de mot de passe (rate limiting)';

-- --------------------------------------------------------

--
-- Structure de la table `postcommentaire`
--

DROP TABLE IF EXISTS `postcommentaire`;
CREATE TABLE IF NOT EXISTS `postcommentaire` (
  `idcommentaire` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `idpost` int UNSIGNED NOT NULL,
  `id` int UNSIGNED NOT NULL,
  `textecommentaire` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `datecommentaire` datetime DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`idcommentaire`),
  KEY `idx_post` (`idpost`),
  KEY `id` (`id`),
  KEY `idx_date` (`datecommentaire`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Commentaires sur les publications';

--
-- Déchargement des données de la table `postcommentaire`
--

INSERT INTO `postcommentaire` (`idcommentaire`, `idpost`, `id`, `textecommentaire`, `datecommentaire`, `created_at`) VALUES
(1, 1, 1, 'mais il n\'y a rien deddans', '2026-03-25 19:19:52', '2026-03-25 11:19:52');

-- --------------------------------------------------------

--
-- Structure de la table `postlike`
--

DROP TABLE IF EXISTS `postlike`;
CREATE TABLE IF NOT EXISTS `postlike` (
  `idlike` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `idpost` int UNSIGNED NOT NULL,
  `id` int UNSIGNED NOT NULL,
  `datelike` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`idlike`),
  UNIQUE KEY `unique_like` (`idpost`,`id`),
  KEY `id` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Likes sur les publications';

--
-- Déchargement des données de la table `postlike`
--

INSERT INTO `postlike` (`idlike`, `idpost`, `id`, `datelike`, `created_at`) VALUES
(2, 1, 2, '2026-03-25 11:19:19', '2026-03-25 11:19:19'),
(5, 2, 2, '2026-03-29 11:26:14', '2026-03-29 11:26:14'),
(6, 3, 2, '2026-03-29 11:26:15', '2026-03-29 11:26:15'),
(7, 6, 1, '2026-03-29 11:57:04', '2026-03-29 11:57:04'),
(8, 5, 1, '2026-03-29 11:57:08', '2026-03-29 11:57:08'),
(9, 1, 1, '2026-03-29 11:57:12', '2026-03-29 11:57:12');

-- --------------------------------------------------------

--
-- Structure de la table `posts`
--

DROP TABLE IF EXISTS `posts`;
CREATE TABLE IF NOT EXISTS `posts` (
  `idpost` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_utilisateur` int UNSIGNED NOT NULL,
  `contenu` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_post` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `privacy` enum('public','friends','private') COLLATE utf8mb4_unicode_ci DEFAULT 'public',
  `shared_from` int UNSIGNED DEFAULT NULL,
  `id_communaute` int UNSIGNED DEFAULT NULL,
  `is_reported` tinyint DEFAULT '0',
  `reported_at` datetime DEFAULT NULL,
  `edited_at` datetime DEFAULT NULL,
  `date_publication` datetime DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`idpost`),
  KEY `idx_utilisateur` (`id_utilisateur`),
  KEY `idx_date` (`date_publication`),
  KEY `idx_privacy` (`privacy`),
  KEY `idx_communaute` (`id_communaute`),
  KEY `idx_reported` (`is_reported`),
  KEY `posts_ibfk_shared` (`shared_from`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Publications des utilisateurs';

--
-- Déchargement des données de la table `posts`
--

INSERT INTO `posts` (`idpost`, `id_utilisateur`, `contenu`, `image_post`, `privacy`, `shared_from`, `id_communaute`, `is_reported`, `reported_at`, `edited_at`, `date_publication`, `created_at`, `updated_at`) VALUES
(1, 1, '', NULL, 'public', NULL, NULL, 0, NULL, NULL, '2026-03-25 19:14:45', '2026-03-25 11:14:45', '2026-03-25 11:14:45'),
(2, 2, 'regardez ce que j\'ai fait avec de l\'intelligence artificielle, pensez-vous que l\'IA pourrait un jour remplacer les graphistes ?', NULL, 'public', NULL, 1, 0, NULL, NULL, '2026-03-25 19:25:48', '2026-03-25 11:25:48', '2026-03-31 16:42:27'),
(3, 2, 'regardez ce que j\'ai fait avec de l\'intelligence artificielle, pensez-vous que l\'IA pourrait un jour remplacer les graphistes ?', NULL, 'public', NULL, 1, 0, NULL, NULL, '2026-03-25 19:25:51', '2026-03-25 11:25:51', '2026-03-31 16:42:27'),
(4, 3, 'Salut a tout le monde', NULL, 'public', NULL, 1, 0, NULL, NULL, '2026-03-28 14:52:43', '2026-03-28 06:52:43', '2026-03-31 16:42:27'),
(5, 1, '', NULL, 'public', NULL, NULL, 0, NULL, NULL, '2026-03-29 19:37:03', '2026-03-29 11:37:03', '2026-03-29 11:37:03'),
(6, 1, '', NULL, 'public', NULL, NULL, 0, NULL, NULL, '2026-03-29 19:37:04', '2026-03-29 11:37:04', '2026-03-29 11:37:04'),
(7, 2, '', NULL, 'public', NULL, NULL, 0, NULL, NULL, '2026-03-30 21:28:12', '2026-03-30 13:28:12', '2026-03-30 13:28:12');

-- --------------------------------------------------------

--
-- Structure de la table `post_reports`
--

DROP TABLE IF EXISTS `post_reports`;
CREATE TABLE IF NOT EXISTS `post_reports` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` int UNSIGNED NOT NULL,
  `reporter_id` int UNSIGNED NOT NULL,
  `reason` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','under_review','action_taken','dismissed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `priority` tinyint UNSIGNED DEFAULT '1',
  `evidence_screenshot` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int UNSIGNED DEFAULT NULL,
  `resolution_notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_post` (`post_id`),
  KEY `idx_reporter` (`reporter_id`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`),
  KEY `idx_created` (`created_at`),
  KEY `post_reports_ibfk_3` (`reviewed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Signalements de contenu inapproprié';

-- --------------------------------------------------------

--
-- Structure de la table `ressources`
--

DROP TABLE IF EXISTS `ressources`;
CREATE TABLE IF NOT EXISTS `ressources` (
  `id_ressource` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `nom_fichier` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `chemin_acces` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type_fichier` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `taille` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_proprietaire` int UNSIGNED NOT NULL,
  `date_upload` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_ressource`),
  KEY `idx_proprietaire` (`id_proprietaire`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ressources partagées';

-- --------------------------------------------------------

--
-- Structure de la table `search_history`
--

DROP TABLE IF EXISTS `search_history`;
CREATE TABLE IF NOT EXISTS `search_history` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `query` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `searched_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_query` (`user_id`,`query`),
  KEY `idx_query` (`query`),
  KEY `idx_date` (`searched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historique des recherches';

-- --------------------------------------------------------

--
-- Structure de la table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
CREATE TABLE IF NOT EXISTS `sessions` (
  `session_id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` text COLLATE utf8mb4_unicode_ci,
  `last_accessed` int UNSIGNED NOT NULL,
  PRIMARY KEY (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `stories`
--

DROP TABLE IF EXISTS `stories`;
CREATE TABLE IF NOT EXISTS `stories` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `media_url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('image','video') COLLATE utf8mb4_unicode_ci DEFAULT 'image',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stories (24h)';

-- --------------------------------------------------------

--
-- Structure de la table `story_views`
--

DROP TABLE IF EXISTS `story_views`;
CREATE TABLE IF NOT EXISTS `story_views` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `story_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `viewed_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_view` (`story_id`,`user_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Vues des stories';

-- --------------------------------------------------------

--
-- Structure de la table `user_blocks`
--

DROP TABLE IF EXISTS `user_blocks`;
CREATE TABLE IF NOT EXISTS `user_blocks` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `blocker_id` int UNSIGNED NOT NULL,
  `blocked_id` int UNSIGNED NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_block` (`blocker_id`,`blocked_id`),
  KEY `idx_blocker` (`blocker_id`),
  KEY `idx_blocked` (`blocked_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Utilisateurs bloqués';

-- --------------------------------------------------------

--
-- Structure de la table `user_preferences`
--

DROP TABLE IF EXISTS `user_preferences`;
CREATE TABLE IF NOT EXISTS `user_preferences` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `dark_mode` tinyint DEFAULT '0',
  `email_notifications` tinyint DEFAULT '1',
  `like_notifications` tinyint DEFAULT '1',
  `comment_notifications` tinyint DEFAULT '1',
  `friend_notifications` tinyint DEFAULT '1',
  `message_notifications` tinyint DEFAULT '1',
  `community_notifications` tinyint DEFAULT '1',
  `language` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT 'fr',
  `timezone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'Europe/Paris',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user` (`user_id`),
  KEY `idx_dark_mode` (`dark_mode`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Préférences utilisateur personnalisables';

--
-- Déchargement des données de la table `user_preferences`
--

INSERT INTO `user_preferences` (`id`, `user_id`, `dark_mode`, `email_notifications`, `like_notifications`, `comment_notifications`, `friend_notifications`, `message_notifications`, `community_notifications`, `language`, `timezone`, `created_at`, `updated_at`) VALUES
(1, 1, 0, 1, 1, 1, 1, 1, 1, 'fr', 'Europe/Paris', '2026-03-25 11:12:59', '2026-03-25 11:12:59'),
(2, 2, 0, 1, 1, 1, 1, 1, 1, 'fr', 'Europe/Paris', '2026-03-25 11:18:13', '2026-03-25 11:18:13'),
(3, 3, 0, 1, 1, 1, 1, 1, 1, 'fr', 'Europe/Paris', '2026-03-28 06:49:54', '2026-03-28 06:49:54');

-- --------------------------------------------------------

--
-- Structure de la table `user_reports`
--

DROP TABLE IF EXISTS `user_reports`;
CREATE TABLE IF NOT EXISTS `user_reports` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `reported_user_id` int UNSIGNED NOT NULL,
  `reporter_id` int UNSIGNED NOT NULL,
  `reason` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','under_review','action_taken','dismissed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_reported` (`reported_user_id`),
  KEY `idx_reporter` (`reporter_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Signalements d''utilisateurs';

-- --------------------------------------------------------

--
-- Structure de la table `utilisateurs`
--

DROP TABLE IF EXISTS `utilisateurs`;
CREATE TABLE IF NOT EXISTS `utilisateurs` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `surnom` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `motdepasse` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT 'default-avatar.png',
  `cover_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio` text COLLATE utf8mb4_unicode_ci,
  `posts_count` int DEFAULT '0',
  `friends_count` int DEFAULT '0',
  `universite` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `faculte` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `niveau_etude` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `profession` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'Etudiant',
  `nationalite` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telephone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `datedenaissance` date DEFAULT NULL,
  `sexe` enum('Masculin','Feminin','Autre') COLLATE utf8mb4_unicode_ci DEFAULT 'Masculin',
  `role` enum('etudiant','professeur','admin') COLLATE utf8mb4_unicode_ci DEFAULT 'etudiant',
  `status` enum('Online','Offline','Away') COLLATE utf8mb4_unicode_ci DEFAULT 'Offline',
  `is_active` tinyint(1) DEFAULT '1',
  `is_verified` tinyint(1) DEFAULT '0',
  `failed_login_attempts` int UNSIGNED DEFAULT '0',
  `locked_until` datetime DEFAULT NULL,
  `remember_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remember_expires` datetime DEFAULT NULL,
  `last_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_activity` datetime DEFAULT NULL,
  `dateinscription` datetime DEFAULT CURRENT_TIMESTAMP,
  `dateconnexion` datetime DEFAULT NULL,
  `two_factor_secret` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `surnom` (`surnom`),
  KEY `idx_email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_universite` (`universite`),
  KEY `idx_nationalite` (`nationalite`),
  KEY `idx_status` (`status`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Utilisateurs du réseau social académique';

--
-- Déchargement des données de la table `utilisateurs`
--

INSERT INTO `utilisateurs` (`id`, `surnom`, `email`, `motdepasse`, `prenom`, `nom`, `avatar`, `cover_image`, `bio`, `posts_count`, `friends_count`, `universite`, `faculte`, `niveau_etude`, `profession`, `nationalite`, `telephone`, `datedenaissance`, `sexe`, `role`, `status`, `is_active`, `is_verified`, `failed_login_attempts`, `locked_until`, `remember_token`, `remember_expires`, `last_ip`, `last_activity`, `dateinscription`, `dateconnexion`, `two_factor_secret`, `created_at`, `updated_at`) VALUES
(1, 'wings_dbn_officiel', 'dvdngwangwa@icloud.com', '$argon2id$v=19$m=65536,t=4,p=3$ZDNPSW0uR1NXWHZnOEM1UA$L7eTyRI7G/+h0eXAWI/W/w7IhgDlubwmMrOWgJg4JyE', 'David', 'Bokokonde', '69c912edb575d_865a09758d4b5112.jpg', NULL, 'Je suis le concepteur de ce site', 0, 0, 'Wuhan University of Technology', 'Computer Sciences', 'Licence 3', 'etudiant', 'République Démocratique du Congo', '+86130622513', '2011-12-29', 'Masculin', 'admin', 'Online', 1, 0, 0, NULL, NULL, NULL, '127.0.0.1', NULL, '2026-03-25 19:12:59', '2026-03-31 07:55:38', NULL, '2026-03-25 11:12:59', '2026-03-30 23:55:38'),
(2, 'wings_dbn', 'dvdngwangwa@gmail.com', '$argon2id$v=19$m=65536,t=4,p=3$STIuTEZjOXRGM2w2eTgzZA$FCaj6tIQrrGigKh9atv7coieEjK3ik+E2wKutioqLoI', 'David', 'Ngwangwa', '69c3c474c5fd7_e1156f0b38a38e2e.png', NULL, 'Je suis le fondateur de ce site.', 0, 0, 'Wuhan University of Technology', 'Sciences Informatiques', 'Licence 3', 'etudiant', 'France', '+243823851403', '2011-12-27', 'Masculin', 'etudiant', 'Online', 1, 0, 0, NULL, NULL, NULL, '127.0.0.1', NULL, '2026-03-25 19:18:13', '2026-03-30 21:02:12', NULL, '2026-03-25 11:18:13', '2026-03-30 13:02:12'),
(3, 'pokouma', 'pokouma@gmail.com', '$argon2id$v=19$m=65536,t=4,p=3$c1Bid0xxZW4vVnFpc045Rw$bLgrjt1PF8mSbJX1y/NsWPTzk8+k6bN6pZOw6U2THgw', 'Patrick', 'Okouma', '69c77a11383d1_aad72b04a764c223.jpg', NULL, 'Je suis Pasteur', 0, 0, 'Wuhan University of Technology', 'Sciences Informatiques', 'Licence 3', 'etudiant', '', '+00000000', '2011-12-29', 'Masculin', 'etudiant', 'Offline', 1, 0, 0, NULL, NULL, NULL, '127.0.0.1', NULL, '2026-03-28 14:49:53', '2026-03-29 19:33:48', NULL, '2026-03-28 06:49:53', '2026-03-29 11:33:52');

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `communautes`
--
ALTER TABLE `communautes` ADD FULLTEXT KEY `idx_search` (`nom`,`description`);

--
-- Index pour la table `posts`
--
ALTER TABLE `posts` ADD FULLTEXT KEY `idx_content` (`contenu`);

--
-- Index pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs` ADD FULLTEXT KEY `idx_search` (`surnom`,`prenom`,`nom`,`email`,`universite`,`faculte`,`profession`,`bio`);

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `ami`
--
ALTER TABLE `ami`
  ADD CONSTRAINT `ami_ibfk_1` FOREIGN KEY (`id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ami_ibfk_2` FOREIGN KEY (`idami`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `communautes`
--
ALTER TABLE `communautes`
  ADD CONSTRAINT `communautes_ibfk_1` FOREIGN KEY (`id_createur`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `communaute_membres`
--
ALTER TABLE `communaute_membres`
  ADD CONSTRAINT `communaute_membres_ibfk_1` FOREIGN KEY (`id_communaute`) REFERENCES `communautes` (`id_communaute`) ON DELETE CASCADE,
  ADD CONSTRAINT `communaute_membres_ibfk_2` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `community_events`
--
ALTER TABLE `community_events`
  ADD CONSTRAINT `community_events_ibfk_1` FOREIGN KEY (`community_id`) REFERENCES `communautes` (`id_communaute`) ON DELETE CASCADE,
  ADD CONSTRAINT `community_events_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `community_resources`
--
ALTER TABLE `community_resources`
  ADD CONSTRAINT `community_resources_ibfk_1` FOREIGN KEY (`community_id`) REFERENCES `communautes` (`id_communaute`) ON DELETE CASCADE,
  ADD CONSTRAINT `community_resources_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `event_participants`
--
ALTER TABLE `event_participants`
  ADD CONSTRAINT `event_participants_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `community_events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `event_participants_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `message`
--
ALTER TABLE `message`
  ADD CONSTRAINT `message_ibfk_1` FOREIGN KEY (`id_expediteur`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `message_ibfk_2` FOREIGN KEY (`id_destinataire`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `postcommentaire`
--
ALTER TABLE `postcommentaire`
  ADD CONSTRAINT `postcommentaire_ibfk_1` FOREIGN KEY (`idpost`) REFERENCES `posts` (`idpost`) ON DELETE CASCADE,
  ADD CONSTRAINT `postcommentaire_ibfk_2` FOREIGN KEY (`id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `postlike`
--
ALTER TABLE `postlike`
  ADD CONSTRAINT `postlike_ibfk_1` FOREIGN KEY (`idpost`) REFERENCES `posts` (`idpost`) ON DELETE CASCADE,
  ADD CONSTRAINT `postlike_ibfk_2` FOREIGN KEY (`id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `posts`
--
ALTER TABLE `posts`
  ADD CONSTRAINT `posts_ibfk_1` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `posts_ibfk_community` FOREIGN KEY (`id_communaute`) REFERENCES `communautes` (`id_communaute`) ON DELETE SET NULL,
  ADD CONSTRAINT `posts_ibfk_shared` FOREIGN KEY (`shared_from`) REFERENCES `posts` (`idpost`) ON DELETE SET NULL;

--
-- Contraintes pour la table `post_reports`
--
ALTER TABLE `post_reports`
  ADD CONSTRAINT `post_reports_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`idpost`) ON DELETE CASCADE,
  ADD CONSTRAINT `post_reports_ibfk_2` FOREIGN KEY (`reporter_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `post_reports_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `ressources`
--
ALTER TABLE `ressources`
  ADD CONSTRAINT `ressources_ibfk_1` FOREIGN KEY (`id_proprietaire`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `search_history`
--
ALTER TABLE `search_history`
  ADD CONSTRAINT `search_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `stories`
--
ALTER TABLE `stories`
  ADD CONSTRAINT `stories_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `story_views`
--
ALTER TABLE `story_views`
  ADD CONSTRAINT `story_views_ibfk_1` FOREIGN KEY (`story_id`) REFERENCES `stories` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `story_views_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `user_blocks`
--
ALTER TABLE `user_blocks`
  ADD CONSTRAINT `user_blocks_ibfk_1` FOREIGN KEY (`blocker_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_blocks_ibfk_2` FOREIGN KEY (`blocked_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `user_preferences`
--
ALTER TABLE `user_preferences`
  ADD CONSTRAINT `user_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `user_reports`
--
ALTER TABLE `user_reports`
  ADD CONSTRAINT `user_reports_ibfk_1` FOREIGN KEY (`reported_user_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_reports_ibfk_2` FOREIGN KEY (`reporter_id`) REFERENCES `utilisateurs` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
