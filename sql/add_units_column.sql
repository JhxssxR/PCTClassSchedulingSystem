-- Add units column to courses table
ALTER TABLE courses ADD COLUMN units INT DEFAULT 3;

-- Update existing courses with default units value
UPDATE courses SET units = 3 WHERE units = 0; 