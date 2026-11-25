/* ====================================================================
   Edu Nursing – RLS Policies
   Migration 002: Row Level Security
   ==================================================================== */

-- Enable RLS on all tables
ALTER TABLE profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE courses ENABLE ROW LEVEL SECURITY;
ALTER TABLE modules ENABLE ROW LEVEL SECURITY;
ALTER TABLE lessons ENABLE ROW LEVEL SECURITY;
ALTER TABLE lesson_attachments ENABLE ROW LEVEL SECURITY;
ALTER TABLE books ENABLE ROW LEVEL SECURITY;
ALTER TABLE enrollments ENABLE ROW LEVEL SECURITY;
ALTER TABLE lesson_progress ENABLE ROW LEVEL SECURITY;
ALTER TABLE orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE order_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE payments ENABLE ROW LEVEL SECURITY;
ALTER TABLE invoices ENABLE ROW LEVEL SECURITY;
ALTER TABLE coupons ENABLE ROW LEVEL SECURITY;
ALTER TABLE meetings ENABLE ROW LEVEL SECURITY;
ALTER TABLE reviews ENABLE ROW LEVEL SECURITY;
ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE messages ENABLE ROW LEVEL SECURITY;
ALTER TABLE announcements ENABLE ROW LEVEL SECURITY;
ALTER TABLE settings ENABLE ROW LEVEL SECURITY;
ALTER TABLE homepage_sections ENABLE ROW LEVEL SECURITY;
ALTER TABLE policy_pages ENABLE ROW LEVEL SECURITY;
ALTER TABLE integrations ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE student_books ENABLE ROW LEVEL SECURITY;

-- ============ HELPER FUNCTIONS ============

-- Get current user role
CREATE OR REPLACE FUNCTION get_user_role()
RETURNS user_role AS $$
  SELECT role FROM profiles WHERE id = auth.uid()
$$ LANGUAGE sql SECURITY DEFINER STABLE;

-- Check if current user is super_admin
CREATE OR REPLACE FUNCTION is_admin()
RETURNS BOOLEAN AS $$
  SELECT EXISTS (SELECT 1 FROM profiles WHERE id = auth.uid() AND role = 'super_admin')
$$ LANGUAGE sql SECURITY DEFINER STABLE;

-- Check if current user is lecturer
CREATE OR REPLACE FUNCTION is_lecturer()
RETURNS BOOLEAN AS $$
  SELECT EXISTS (SELECT 1 FROM profiles WHERE id = auth.uid() AND role = 'lecturer')
$$ LANGUAGE sql SECURITY DEFINER STABLE;

-- ============ PROFILES ============

CREATE POLICY "Users can view their own profile"
  ON profiles FOR SELECT
  USING (id = auth.uid() OR is_admin());

CREATE POLICY "Users can update their own profile"
  ON profiles FOR UPDATE
  USING (id = auth.uid())
  WITH CHECK (id = auth.uid());

CREATE POLICY "Admin can view all profiles"
  ON profiles FOR SELECT
  USING (is_admin());

CREATE POLICY "Admin can update any profile"
  ON profiles FOR UPDATE
  USING (is_admin());

CREATE POLICY "Admin can insert profiles"
  ON profiles FOR INSERT
  WITH CHECK (is_admin() OR id = auth.uid());

-- ============ COURSES ============

CREATE POLICY "Anyone can view published courses"
  ON courses FOR SELECT
  USING (status = 'published');

CREATE POLICY "Admin can view all courses"
  ON courses FOR SELECT
  USING (is_admin());

CREATE POLICY "Lecturers can view own courses"
  ON courses FOR SELECT
  USING (instructor_id = auth.uid());

CREATE POLICY "Admin can manage courses"
  ON courses FOR ALL
  USING (is_admin());

CREATE POLICY "Lecturer can update own courses"
  ON courses FOR UPDATE
  USING (instructor_id = auth.uid())
  WITH CHECK (instructor_id = auth.uid());

-- ============ MODULES & LESSONS ============

CREATE POLICY "Public can view modules of published courses"
  ON modules FOR SELECT
  USING (EXISTS (SELECT 1 FROM courses WHERE courses.id = modules.course_id AND courses.status = 'published'));

CREATE POLICY "Admin can manage modules"
  ON modules FOR ALL
  USING (is_admin());

CREATE POLICY "Public can view lessons of published courses"
  ON lessons FOR SELECT
  USING (EXISTS (
    SELECT 1 FROM modules m
    JOIN courses c ON c.id = m.course_id
    WHERE m.id = lessons.module_id AND c.status = 'published'
  ));

CREATE POLICY "Admin can manage lessons"
  ON lessons FOR ALL
  USING (is_admin());

-- ============ BOOKS ============

CREATE POLICY "Anyone can view books"
  ON books FOR SELECT
  USING (true);

CREATE POLICY "Admin can manage books"
  ON books FOR ALL
  USING (is_admin());

-- ============ ENROLLMENTS ============

CREATE POLICY "Students can view own enrollments"
  ON enrollments FOR SELECT
  USING (student_id = auth.uid());

CREATE POLICY "Admin can view all enrollments"
  ON enrollments FOR SELECT
  USING (is_admin());

CREATE POLICY "Admin can manage enrollments"
  ON enrollments FOR ALL
  USING (is_admin());

-- ============ ORDERS ============

CREATE POLICY "Students can view own orders"
  ON orders FOR SELECT
  USING (student_id = auth.uid());

CREATE POLICY "Students can create orders"
  ON orders FOR INSERT
  WITH CHECK (student_id = auth.uid());

CREATE POLICY "Admin can view all orders"
  ON orders FOR ALL
  USING (is_admin());

-- ============ PAYMENTS ============

CREATE POLICY "Students can view own payments"
  ON payments FOR SELECT
  USING (EXISTS (SELECT 1 FROM orders WHERE orders.id = payments.order_id AND orders.student_id = auth.uid()));

CREATE POLICY "Students can create payments"
  ON payments FOR INSERT
  WITH CHECK (EXISTS (SELECT 1 FROM orders WHERE orders.id = payments.order_id AND orders.student_id = auth.uid()));

CREATE POLICY "Admin can manage payments"
  ON payments FOR ALL
  USING (is_admin());

-- ============ NOTIFICATIONS ============

CREATE POLICY "Users can view own notifications"
  ON notifications FOR SELECT
  USING (user_id = auth.uid());

CREATE POLICY "Users can update own notifications"
  ON notifications FOR UPDATE
  USING (user_id = auth.uid());

CREATE POLICY "Admin can manage notifications"
  ON notifications FOR ALL
  USING (is_admin());

-- ============ MESSAGES ============

CREATE POLICY "Users can view own messages"
  ON messages FOR SELECT
  USING (sender_id = auth.uid() OR receiver_id = auth.uid());

CREATE POLICY "Users can send messages"
  ON messages FOR INSERT
  WITH CHECK (sender_id = auth.uid());

CREATE POLICY "Admin can view all messages"
  ON messages FOR SELECT
  USING (is_admin());

-- ============ REVIEWS ============

CREATE POLICY "Anyone can view approved reviews"
  ON reviews FOR SELECT
  USING (is_approved = true);

CREATE POLICY "Students can create reviews"
  ON reviews FOR INSERT
  WITH CHECK (student_id = auth.uid());

CREATE POLICY "Admin can manage reviews"
  ON reviews FOR ALL
  USING (is_admin());

-- ============ SETTINGS (admin‑only) ============

CREATE POLICY "Anyone can read settings"
  ON settings FOR SELECT
  USING (true);

CREATE POLICY "Admin can manage settings"
  ON settings FOR ALL
  USING (is_admin());

-- ============ HOMEPAGE & POLICIES ============

CREATE POLICY "Public read homepage sections"
  ON homepage_sections FOR SELECT USING (true);

CREATE POLICY "Admin manages homepage"
  ON homepage_sections FOR ALL USING (is_admin());

CREATE POLICY "Public read policy pages"
  ON policy_pages FOR SELECT USING (true);

CREATE POLICY "Admin manages policy pages"
  ON policy_pages FOR ALL USING (is_admin());

-- ============ INTEGRATIONS (admin‑only) ============

CREATE POLICY "Admin manages integrations"
  ON integrations FOR ALL USING (is_admin());

-- ============ AUDIT LOGS ============

CREATE POLICY "Admin can view audit logs"
  ON audit_logs FOR SELECT USING (is_admin());

-- Service role will INSERT audit logs from API routes (bypasses RLS).
