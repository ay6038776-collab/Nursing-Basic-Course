/* ====================================================================
   Edu Nursing – Database Schema Migration
   Migration 001: Core tables
   ==================================================================== */

-- ---------------------
-- ENUM TYPES
-- ---------------------
CREATE TYPE user_role AS ENUM ('super_admin', 'lecturer', 'student');
CREATE TYPE course_status AS ENUM ('draft', 'published', 'archived');
CREATE TYPE book_type AS ENUM ('print', 'digital', 'both');
CREATE TYPE order_status AS ENUM (
  'pending_payment',
  'pending_review',
  'paid',
  'in_preparation',
  'shipped',
  'delivered',
  'rejected',
  'cancelled'
);
CREATE TYPE payment_method AS ENUM ('vodafone_cash', 'instapay', 'online_gateway');
CREATE TYPE payment_status AS ENUM ('pending', 'approved', 'rejected');
CREATE TYPE product_type AS ENUM ('course', 'book');
CREATE TYPE lesson_type AS ENUM ('video', 'pdf', 'text');
CREATE TYPE notification_type AS ENUM (
  'payment', 'order', 'enrollment', 'meeting',
  'message', 'announcement', 'system'
);

-- ---------------------
-- PROFILES
-- ---------------------
CREATE TABLE IF NOT EXISTS profiles (
  id UUID PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
  full_name TEXT NOT NULL DEFAULT '',
  phone TEXT,
  avatar_url TEXT,
  role user_role NOT NULL DEFAULT 'student',
  bio TEXT,
  specialization TEXT,
  language TEXT DEFAULT 'ar',
  theme TEXT DEFAULT 'light',
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- CATEGORIES
-- ---------------------
CREATE TABLE IF NOT EXISTS categories (
  id SERIAL PRIMARY KEY,
  name_ar TEXT NOT NULL,
  name_en TEXT,
  slug TEXT NOT NULL UNIQUE,
  parent_id INTEGER REFERENCES categories(id),
  sort_order INTEGER DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- COURSES
-- ---------------------
CREATE TABLE IF NOT EXISTS courses (
  id SERIAL PRIMARY KEY,
  title TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  description TEXT,
  marketing_description TEXT,
  objectives TEXT[],
  target_audience TEXT,
  requirements TEXT,
  cover_url TEXT,
  price NUMERIC(10,2) NOT NULL DEFAULT 0,
  discount_price NUMERIC(10,2),
  status course_status NOT NULL DEFAULT 'draft',
  instructor_id UUID REFERENCES profiles(id),
  category_id INTEGER REFERENCES categories(id),
  duration_hours NUMERIC(6,2),
  is_featured BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- MODULES
-- ---------------------
CREATE TABLE IF NOT EXISTS modules (
  id SERIAL PRIMARY KEY,
  course_id INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  title TEXT NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- LESSONS
-- ---------------------
CREATE TABLE IF NOT EXISTS lessons (
  id SERIAL PRIMARY KEY,
  module_id INTEGER NOT NULL REFERENCES modules(id) ON DELETE CASCADE,
  title TEXT NOT NULL,
  type lesson_type NOT NULL DEFAULT 'video',
  content_url TEXT,
  text_content TEXT,
  duration_seconds INTEGER DEFAULT 0,
  is_free BOOLEAN DEFAULT FALSE,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- LESSON ATTACHMENTS
-- ---------------------
CREATE TABLE IF NOT EXISTS lesson_attachments (
  id SERIAL PRIMARY KEY,
  lesson_id INTEGER NOT NULL REFERENCES lessons(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  file_url TEXT NOT NULL,
  file_type TEXT,
  file_size BIGINT DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- BOOKS
-- ---------------------
CREATE TABLE IF NOT EXISTS books (
  id SERIAL PRIMARY KEY,
  title_ar TEXT NOT NULL,
  title_en TEXT,
  slug TEXT NOT NULL UNIQUE,
  description TEXT,
  objectives TEXT[],
  cover_url TEXT,
  preview_url TEXT,
  pdf_url TEXT,
  type book_type NOT NULL DEFAULT 'digital',
  price NUMERIC(10,2) NOT NULL DEFAULT 0,
  discount_price NUMERIC(10,2),
  stock INTEGER DEFAULT 0,
  category_id INTEGER REFERENCES categories(id),
  is_featured BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- ENROLLMENTS
-- ---------------------
CREATE TABLE IF NOT EXISTS enrollments (
  id SERIAL PRIMARY KEY,
  student_id UUID NOT NULL REFERENCES profiles(id),
  course_id INTEGER NOT NULL REFERENCES courses(id),
  enrolled_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  completed_at TIMESTAMPTZ,
  progress NUMERIC(5,2) DEFAULT 0,
  UNIQUE (student_id, course_id)
);

-- ---------------------
-- LESSON PROGRESS
-- ---------------------
CREATE TABLE IF NOT EXISTS lesson_progress (
  id SERIAL PRIMARY KEY,
  student_id UUID NOT NULL REFERENCES profiles(id),
  lesson_id INTEGER NOT NULL REFERENCES lessons(id),
  watched_seconds INTEGER DEFAULT 0,
  is_completed BOOLEAN DEFAULT FALSE,
  last_position INTEGER DEFAULT 0,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (student_id, lesson_id)
);

-- ---------------------
-- ORDERS
-- ---------------------
CREATE TABLE IF NOT EXISTS orders (
  id SERIAL PRIMARY KEY,
  order_number TEXT NOT NULL UNIQUE,
  student_id UUID NOT NULL REFERENCES profiles(id),
  status order_status NOT NULL DEFAULT 'pending_payment',
  subtotal NUMERIC(10,2) NOT NULL DEFAULT 0,
  discount NUMERIC(10,2) DEFAULT 0,
  shipping_cost NUMERIC(10,2) DEFAULT 0,
  total NUMERIC(10,2) NOT NULL DEFAULT 0,
  shipping_address TEXT,
  shipping_phone TEXT,
  notes TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- ORDER ITEMS
-- ---------------------
CREATE TABLE IF NOT EXISTS order_items (
  id SERIAL PRIMARY KEY,
  order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  product_type product_type NOT NULL,
  product_id INTEGER NOT NULL,
  quantity INTEGER NOT NULL DEFAULT 1,
  unit_price NUMERIC(10,2) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- PAYMENTS
-- ---------------------
CREATE TABLE IF NOT EXISTS payments (
  id SERIAL PRIMARY KEY,
  order_id INTEGER NOT NULL REFERENCES orders(id),
  method payment_method NOT NULL,
  receipt_url TEXT,
  reference_number TEXT,
  amount NUMERIC(10,2) NOT NULL DEFAULT 0,
  status payment_status NOT NULL DEFAULT 'pending',
  rejection_reason TEXT,
  processed_by UUID REFERENCES profiles(id),
  processed_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- INVOICES
-- ---------------------
CREATE TABLE IF NOT EXISTS invoices (
  id SERIAL PRIMARY KEY,
  invoice_number TEXT NOT NULL UNIQUE,
  order_id INTEGER NOT NULL REFERENCES orders(id),
  student_id UUID NOT NULL REFERENCES profiles(id),
  subtotal NUMERIC(10,2) NOT NULL,
  discount NUMERIC(10,2) DEFAULT 0,
  total NUMERIC(10,2) NOT NULL,
  payment_method payment_method,
  payment_status payment_status,
  pdf_url TEXT,
  issued_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- COUPONS
-- ---------------------
CREATE TABLE IF NOT EXISTS coupons (
  id SERIAL PRIMARY KEY,
  code TEXT NOT NULL UNIQUE,
  discount_type TEXT NOT NULL DEFAULT 'percentage', -- 'percentage' | 'fixed'
  discount_value NUMERIC(10,2) NOT NULL,
  max_uses INTEGER,
  used_count INTEGER DEFAULT 0,
  valid_from TIMESTAMPTZ,
  valid_until TIMESTAMPTZ,
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- MEETINGS
-- ---------------------
CREATE TABLE IF NOT EXISTS meetings (
  id SERIAL PRIMARY KEY,
  course_id INTEGER REFERENCES courses(id),
  instructor_id UUID REFERENCES profiles(id),
  title TEXT NOT NULL,
  description TEXT,
  zoom_link TEXT,
  zoom_password TEXT,
  start_time TIMESTAMPTZ NOT NULL,
  duration_minutes INTEGER DEFAULT 60,
  is_cancelled BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- REVIEWS
-- ---------------------
CREATE TABLE IF NOT EXISTS reviews (
  id SERIAL PRIMARY KEY,
  student_id UUID NOT NULL REFERENCES profiles(id),
  course_id INTEGER NOT NULL REFERENCES courses(id),
  rating SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
  comment TEXT,
  is_approved BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (student_id, course_id)
);

-- ---------------------
-- NOTIFICATIONS
-- ---------------------
CREATE TABLE IF NOT EXISTS notifications (
  id SERIAL PRIMARY KEY,
  user_id UUID NOT NULL REFERENCES profiles(id),
  type notification_type NOT NULL DEFAULT 'system',
  title TEXT NOT NULL,
  body TEXT,
  payload JSONB,
  is_read BOOLEAN DEFAULT FALSE,
  read_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- MESSAGES
-- ---------------------
CREATE TABLE IF NOT EXISTS messages (
  id SERIAL PRIMARY KEY,
  sender_id UUID NOT NULL REFERENCES profiles(id),
  receiver_id UUID NOT NULL REFERENCES profiles(id),
  subject TEXT,
  body TEXT NOT NULL,
  is_read BOOLEAN DEFAULT FALSE,
  read_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- ANNOUNCEMENTS
-- ---------------------
CREATE TABLE IF NOT EXISTS announcements (
  id SERIAL PRIMARY KEY,
  title TEXT NOT NULL,
  body TEXT NOT NULL,
  course_id INTEGER REFERENCES courses(id), -- NULL = global announcement
  created_by UUID REFERENCES profiles(id),
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- SETTINGS (key‑value store)
-- ---------------------
CREATE TABLE IF NOT EXISTS settings (
  key TEXT PRIMARY KEY,
  value JSONB NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- HOMEPAGE SECTIONS
-- ---------------------
CREATE TABLE IF NOT EXISTS homepage_sections (
  id SERIAL PRIMARY KEY,
  section_key TEXT NOT NULL UNIQUE,
  title TEXT,
  content JSONB,
  is_visible BOOLEAN DEFAULT TRUE,
  sort_order INTEGER DEFAULT 0,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- POLICIES (pages)
-- ---------------------
CREATE TABLE IF NOT EXISTS policy_pages (
  id SERIAL PRIMARY KEY,
  slug TEXT NOT NULL UNIQUE, -- 'terms', 'privacy', 'refund'
  title TEXT NOT NULL,
  content TEXT NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- INTEGRATIONS
-- ---------------------
CREATE TABLE IF NOT EXISTS integrations (
  id SERIAL PRIMARY KEY,
  service_name TEXT NOT NULL UNIQUE, -- 'zoom', 'smtp', 'sms', 'payment_gateway', 'storage'
  config JSONB NOT NULL DEFAULT '{}',
  is_connected BOOLEAN DEFAULT FALSE,
  tested_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- AUDIT LOG
-- ---------------------
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGSERIAL PRIMARY KEY,
  user_id UUID REFERENCES profiles(id),
  action TEXT NOT NULL,
  resource_type TEXT,
  resource_id TEXT,
  details JSONB,
  ip_address TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------
-- STUDENT BOOKS (purchased books)
-- ---------------------
CREATE TABLE IF NOT EXISTS student_books (
  id SERIAL PRIMARY KEY,
  student_id UUID NOT NULL REFERENCES profiles(id),
  book_id INTEGER NOT NULL REFERENCES books(id),
  purchased_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (student_id, book_id)
);

-- ---------------------
-- INDEXES
-- ---------------------
CREATE INDEX idx_courses_status ON courses(status);
CREATE INDEX idx_courses_instructor ON courses(instructor_id);
CREATE INDEX idx_orders_student ON orders(student_id);
CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_payments_status ON payments(status);
CREATE INDEX idx_enrollments_student ON enrollments(student_id);
CREATE INDEX idx_notifications_user ON notifications(user_id);
CREATE INDEX idx_audit_logs_user ON audit_logs(user_id);
CREATE INDEX idx_audit_logs_created ON audit_logs(created_at);
