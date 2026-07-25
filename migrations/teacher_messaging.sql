-- ============================================================================
-- TEACHER MESSAGING — 1-to-1 chat between staff accounts.
--
-- DESIGN
--   chat_conversation  one row per PAIR of people (not per message thread)
--   chat_message       the messages in it
--
-- THE KEY TRICK — canonical participant ordering:
--   user_low  = LEAST(a, b)
--   user_high = GREATEST(a, b)
--   UNIQUE (user_low, user_high)
--
--   Storing the pair in a fixed order and making it UNIQUE means A→B and B→A
--   resolve to the SAME conversation row. Without it, two people opening a chat
--   simultaneously would create two threads and each would see half the history.
--   The database enforces it, so no application race can produce a duplicate.
--
-- AUTHORIZATION: a user may read a conversation only if their id is user_low or
--   user_high. That single predicate is the whole access rule (see
--   message_service.php :: msg_can_access).
--
-- Participants are user_account ids, not teacher ids — so the same tables serve
-- teacher↔teacher today and teacher↔dept-head later with no schema change.
--
-- Idempotent & portable. Safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `chat_conversation` (
  `id`              int(11)   NOT NULL AUTO_INCREMENT,
  `user_low`        int(11)   NOT NULL COMMENT 'LEAST(participant ids) — canonical',
  `user_high`       int(11)   NOT NULL COMMENT 'GREATEST(participant ids) — canonical',
  `last_message_at` datetime  DEFAULT NULL COMMENT 'Denormalized for cheap inbox ordering',
  `last_message`    varchar(160) DEFAULT NULL COMMENT 'Preview snippet for the inbox list',
  `created_at`      timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_pair` (`user_low`, `user_high`),
  KEY `idx_chat_low`  (`user_low`, `last_message_at`),
  KEY `idx_chat_high` (`user_high`, `last_message_at`),
  CONSTRAINT `fk_chat_low`  FOREIGN KEY (`user_low`)
      REFERENCES `user_account` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_chat_high` FOREIGN KEY (`user_high`)
      REFERENCES `user_account` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_chat_order` CHECK (`user_low` < `user_high`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `chat_message` (
  `id`              bigint(20) NOT NULL AUTO_INCREMENT,
  `conversation_id` int(11)    NOT NULL,
  `sender_id`       int(11)    NOT NULL,
  `body`            varchar(2000) NOT NULL,
  `read_at`         datetime   DEFAULT NULL COMMENT 'NULL = unread by the recipient',
  `created_at`      timestamp  NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_msg_conv`   (`conversation_id`, `id`),
  KEY `idx_msg_unread` (`conversation_id`, `sender_id`, `read_at`),
  CONSTRAINT `fk_msg_conv` FOREIGN KEY (`conversation_id`)
      REFERENCES `chat_conversation` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_msg_sender` FOREIGN KEY (`sender_id`)
      REFERENCES `user_account` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- DONE.
-- ============================================================================
