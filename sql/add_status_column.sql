-- Add status column to classrooms table
ALTER TABLE classrooms ADD COLUMN status ENUM('active', 'inactive') NOT NULL DEFAULT 'active';

-- Update existing records to be active
UPDATE classrooms SET status = 'active' WHERE status IS NULL; 