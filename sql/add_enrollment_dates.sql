-- Add date columns to enrollments table
ALTER TABLE enrollments 
ADD COLUMN enrolled_at DATETIME NULL,
ADD COLUMN dropped_at DATETIME NULL,
ADD COLUMN rejected_at DATETIME NULL;

-- Update existing records to have enrolled_at set to current time
UPDATE enrollments SET enrolled_at = NOW() WHERE status = 'enrolled' AND enrolled_at IS NULL; 