-- Update any schedules that don't have a status to 'active'
UPDATE schedules SET status = 'active' WHERE status IS NULL;

-- Add status column if it doesn't exist
ALTER TABLE schedules ADD COLUMN IF NOT EXISTS status ENUM('active', 'cancelled', 'completed') NOT NULL DEFAULT 'active'; 