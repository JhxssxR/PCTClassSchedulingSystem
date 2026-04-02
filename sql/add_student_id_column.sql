-- Add student_id column to users table
ALTER TABLE users ADD COLUMN student_id VARCHAR(20) UNIQUE;

-- Update existing student records with a generated student ID
UPDATE users 
SET student_id = CONCAT('STU', LPAD(id, 6, '0'))
WHERE role = 'student' AND student_id IS NULL; 