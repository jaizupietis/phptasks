-- Add new columns to existing tables
ALTER TABLE users ADD COLUMN profile_image varchar(255) DEFAULT NULL;
ALTER TABLE users ADD COLUMN preferences longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(preferences));

ALTER TABLE tasks ADD COLUMN attachments longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(attachments));
ALTER TABLE tasks ADD COLUMN tags varchar(500) DEFAULT NULL;

ALTER TABLE notifications ADD COLUMN priority enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal';
ALTER TABLE notifications ADD COLUMN metadata longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(metadata));

-- Create new tables
CREATE TABLE IF NOT EXISTS task_comments (
  id int(11) NOT NULL AUTO_INCREMENT,
  task_id int(11) NOT NULL,
  user_id int(11) NOT NULL,
  comment text NOT NULL,
  is_internal tinyint(1) NOT NULL DEFAULT 0,
  attachments longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(attachments)),
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_task_id (task_id),
  KEY idx_user_id (user_id),
  KEY idx_created_at (created_at),
  CONSTRAINT fk_comment_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
  CONSTRAINT fk_comment_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id int(11) NOT NULL AUTO_INCREMENT,
  name varchar(100) NOT NULL,
  description text DEFAULT NULL,
  color varchar(7) DEFAULT '#007bff',
  icon varchar(50) DEFAULT 'fas fa-tag',
  is_active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY unique_name (name),
  KEY idx_active (is_active),
  KEY idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample categories
INSERT IGNORE INTO categories (name, description, color, icon) VALUES
('Preventive Maintenance', 'Scheduled maintenance tasks', '#28a745', 'fas fa-calendar-check'),
('Repair', 'Equipment repair tasks', '#dc3545', 'fas fa-tools'),
('Inspection', 'Safety and quality inspections', '#17a2b8', 'fas fa-search'),
('Installation', 'New equipment installation', '#6f42c1', 'fas fa-cogs'),
('Emergency', 'Urgent emergency repairs', '#fd7e14', 'fas fa-exclamation-triangle'),
('Testing', 'Equipment testing and diagnostics', '#20c997', 'fas fa-flask');

-- Add useful indexes
CREATE INDEX IF NOT EXISTS idx_tasks_status_priority ON tasks(status, priority);
CREATE INDEX IF NOT EXISTS idx_tasks_assigned_status ON tasks(assigned_to, status);
CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications(user_id, is_read);
