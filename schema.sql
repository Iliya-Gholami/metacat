
CREATE DATABASE IF NOT EXISTS search_engine
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE TABLE search_engine.documents (
    url           VARCHAR(2083) PRIMARY KEY,
    title         TEXT      NOT NULL,
    content       TEXT      NOT NULL,
    type          ENUM('video','photo','text') NOT NULL,
    thumbnail       TEXT      NULL,
    modified_at   DATETIME  NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_title (title(191)),
    FULLTEXT INDEX ft_content (content)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE search_engine.search_index (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    term        VARCHAR(191) NOT NULL,
    doc_url     VARCHAR(2083) NOT NULL,
    frequency   INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_term_doc (term, doc_url),
    INDEX idx_term (term(191)),
    INDEX idx_doc (doc_url),
    FOREIGN KEY (doc_url) REFERENCES documents(url)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE search_engine.links (
    source_url VARCHAR(2083) NOT NULL,
    target_url VARCHAR(2083) NOT NULL,
    PRIMARY KEY (source_url, target_url),
    FOREIGN KEY (source_url) REFERENCES documents(url)
        ON DELETE CASCADE,
    FOREIGN KEY (target_url) REFERENCES documents(url)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE search_engine.page_rank (
    url        VARCHAR(2083) PRIMARY KEY,
    rank       DOUBLE NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (url) REFERENCES documents(url)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;