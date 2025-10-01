-- Migrazione per il sistema di prima password
-- Aggiunge campi alla tabella users per gestire il reset password e il primo accesso

-- Aggiungi campi alla tabella users
ALTER TABLE users
ADD COLUMN IF NOT EXISTS password_reset_token VARCHAR(255) DEFAULT NULL AFTER password_hash,
ADD COLUMN IF NOT EXISTS password_reset_expires DATETIME DEFAULT NULL AFTER password_reset_token,
ADD COLUMN IF NOT EXISTS first_login BOOLEAN DEFAULT TRUE AFTER password_reset_expires;

-- Aggiungi indice sul token per ricerche più veloci
CREATE INDEX IF NOT EXISTS idx_password_reset_token ON users(password_reset_token);

-- Aggiorna gli utenti esistenti per marcarli come non al primo login
UPDATE users SET first_login = FALSE WHERE password_hash IS NOT NULL AND password_hash != '';

-- Aggiungi colonna per tracciare quando l'utente ha impostato la password
ALTER TABLE users
ADD COLUMN IF NOT EXISTS password_set_at DATETIME DEFAULT NULL AFTER first_login;

-- Aggiungi colonna per tracciare invii email
ALTER TABLE users
ADD COLUMN IF NOT EXISTS welcome_email_sent_at DATETIME DEFAULT NULL AFTER password_set_at;

-- Tabella per tracciare i tentativi di reset password (per rate limiting)
CREATE TABLE IF NOT EXISTS password_reset_attempts (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    success BOOLEAN DEFAULT FALSE,
    INDEX idx_email_attempts (email),
    INDEX idx_ip_attempts (ip_address),
    INDEX idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pulizia automatica dei vecchi tentativi (più vecchi di 24 ore)
DELIMITER //
CREATE EVENT IF NOT EXISTS clean_password_reset_attempts
ON SCHEDULE EVERY 1 DAY
DO
BEGIN
    DELETE FROM password_reset_attempts
    WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);
END//
DELIMITER ;