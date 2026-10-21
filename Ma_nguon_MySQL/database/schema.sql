-- MySQL >= 8.0.16; run only in a separate lab database.
CREATE TABLE IF NOT EXISTS events (
    id integer PRIMARY KEY,
    title varchar(160) NOT NULL,
    category varchar(30) NOT NULL,
    capacity integer NOT NULL CHECK (capacity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS registrations (
    id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id integer NOT NULL,
    name varchar(120) NOT NULL CHECK (length(trim(name)) > 0),
    email varchar(200) NOT NULL,
    created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT normalized_email CHECK (BINARY email = BINARY lower(trim(email))),
    CONSTRAINT registrations_event_fk FOREIGN KEY (event_id) REFERENCES events(id),
    UNIQUE (event_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
-- A no-op for existing seed IDs; do not overwrite students' existing event data.
INSERT INTO events (id,title,category,capacity) VALUES
(1,'PHP Open Day','tech',10),(2,'Design Workshop','design',5),
(3,'Git Clinic','tech',20) ON DUPLICATE KEY UPDATE id=events.id;
