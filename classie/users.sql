-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: users_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('student','teacher','admin') NOT NULL DEFAULT 'student',
  `class` varchar(50) DEFAULT NULL,
  `assigned_teacher_id` int(11) DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `sections` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (2,'admin','admin@gmail.com','$2y$10$h5jV1dyuVEeXmCcCj1cLPuBHHC3DrquCl/4ydLxJZdasYyXyJxg7O','teacher','eng,math02',NULL,NULL,'61'),(22,'administrator','ad@gmail.com','$2y$10$JzUTqxRZuKKtlBZAihYFpeGnX5zEYkfZBEfkEErmDYKqvjUltH9eO','admin',NULL,NULL,NULL,NULL),(23,'administrator','adm@gmail.com','$2y$10$m.gUioslr1BhzpqD274iKObFLHw2GX/JrZglWUc99SmeZ.6hsvzqi','admin',NULL,NULL,NULL,NULL),(24,'JAMES','james@gmail.com','$2y$10$K9MPdQuhYqyg1XiH81cXierj.3JdKET1wMPfyPjaZ3vnUS3.ENcBq','teacher',NULL,NULL,NULL,'61'),(26,'James Preston Vale','jpv@gmail.com','$2y$10$GdHXdYhPEA5PPelRWOPuCeIMi0vK/xNDO/CDrmIhJCJoQbG/gmJcS','student',NULL,NULL,459,NULL),(27,'ad','ae@gmail.com','$2y$10$tZ3WbG6SgcIpRDfkx2tyMeYNGnrrOgvZMNH9AkWN4gCDgbzf7PLxu','student','eng',NULL,61,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-05-27 12:59:22
