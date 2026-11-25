/* ====================================================================
   Edu Nursing – TypeScript Types
   Mirrors the PostgreSQL schema for type‑safe frontend usage.
   ==================================================================== */

export type UserRole = 'super_admin' | 'lecturer' | 'student';
export type CourseStatus = 'draft' | 'published' | 'archived';
export type BookType = 'print' | 'digital' | 'both';
export type OrderStatus =
  | 'pending_payment'
  | 'pending_review'
  | 'paid'
  | 'in_preparation'
  | 'shipped'
  | 'delivered'
  | 'rejected'
  | 'cancelled';
export type PaymentMethod = 'vodafone_cash' | 'instapay' | 'online_gateway';
export type PaymentStatusType = 'pending' | 'approved' | 'rejected';
export type LessonType = 'video' | 'pdf' | 'text';
export type NotificationType =
  | 'payment'
  | 'order'
  | 'enrollment'
  | 'meeting'
  | 'message'
  | 'announcement'
  | 'system';

// ─── Profile ──────────────────────────────────────────────
export interface Profile {
  id: string;
  full_name: string;
  phone?: string;
  avatar_url?: string;
  role: UserRole;
  bio?: string;
  specialization?: string;
  language: string;
  theme: string;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

// ─── Category ─────────────────────────────────────────────
export interface Category {
  id: number;
  name_ar: string;
  name_en?: string;
  slug: string;
  parent_id?: number;
  sort_order: number;
  created_at: string;
}

// ─── Course ───────────────────────────────────────────────
export interface Course {
  id: number;
  title: string;
  slug: string;
  description?: string;
  marketing_description?: string;
  objectives?: string[];
  target_audience?: string;
  requirements?: string;
  cover_url?: string;
  price: number;
  discount_price?: number;
  status: CourseStatus;
  instructor_id?: string;
  category_id?: number;
  duration_hours?: number;
  is_featured: boolean;
  created_at: string;
  updated_at: string;
  // Joined
  instructor?: Profile;
  category?: Category;
  modules?: Module[];
  reviews?: Review[];
}

// ─── Module ───────────────────────────────────────────────
export interface Module {
  id: number;
  course_id: number;
  title: string;
  sort_order: number;
  created_at: string;
  lessons?: Lesson[];
}

// ─── Lesson ───────────────────────────────────────────────
export interface Lesson {
  id: number;
  module_id: number;
  title: string;
  type: LessonType;
  content_url?: string;
  text_content?: string;
  duration_seconds: number;
  is_free: boolean;
  sort_order: number;
  created_at: string;
  updated_at: string;
  attachments?: LessonAttachment[];
}

// ─── Lesson Attachment ────────────────────────────────────
export interface LessonAttachment {
  id: number;
  lesson_id: number;
  name: string;
  file_url: string;
  file_type?: string;
  file_size: number;
  created_at: string;
}

// ─── Book ─────────────────────────────────────────────────
export interface Book {
  id: number;
  title_ar: string;
  title_en?: string;
  slug: string;
  description?: string;
  objectives?: string[];
  cover_url?: string;
  preview_url?: string;
  pdf_url?: string;
  type: BookType;
  price: number;
  discount_price?: number;
  stock: number;
  category_id?: number;
  is_featured: boolean;
  created_at: string;
  updated_at: string;
}

// ─── Enrollment ───────────────────────────────────────────
export interface Enrollment {
  id: number;
  student_id: string;
  course_id: number;
  enrolled_at: string;
  completed_at?: string;
  progress: number;
  course?: Course;
}

// ─── Lesson Progress ─────────────────────────────────────
export interface LessonProgress {
  id: number;
  student_id: string;
  lesson_id: number;
  watched_seconds: number;
  is_completed: boolean;
  last_position: number;
  updated_at: string;
}

// ─── Order ────────────────────────────────────────────────
export interface Order {
  id: number;
  order_number: string;
  student_id: string;
  status: OrderStatus;
  subtotal: number;
  discount: number;
  shipping_cost: number;
  total: number;
  shipping_address?: string;
  shipping_phone?: string;
  notes?: string;
  created_at: string;
  updated_at: string;
  items?: OrderItem[];
  payments?: Payment[];
  student?: Profile;
}

// ─── Order Item ───────────────────────────────────────────
export interface OrderItem {
  id: number;
  order_id: number;
  product_type: 'course' | 'book';
  product_id: number;
  quantity: number;
  unit_price: number;
  created_at: string;
}

// ─── Payment ──────────────────────────────────────────────
export interface Payment {
  id: number;
  order_id: number;
  method: PaymentMethod;
  receipt_url?: string;
  reference_number?: string;
  amount: number;
  status: PaymentStatusType;
  rejection_reason?: string;
  processed_by?: string;
  processed_at?: string;
  created_at: string;
}

// ─── Invoice ──────────────────────────────────────────────
export interface Invoice {
  id: number;
  invoice_number: string;
  order_id: number;
  student_id: string;
  subtotal: number;
  discount: number;
  total: number;
  payment_method?: PaymentMethod;
  payment_status?: PaymentStatusType;
  pdf_url?: string;
  issued_at: string;
}

// ─── Coupon ───────────────────────────────────────────────
export interface Coupon {
  id: number;
  code: string;
  discount_type: 'percentage' | 'fixed';
  discount_value: number;
  max_uses?: number;
  used_count: number;
  valid_from?: string;
  valid_until?: string;
  is_active: boolean;
  created_at: string;
}

// ─── Meeting ──────────────────────────────────────────────
export interface Meeting {
  id: number;
  course_id?: number;
  instructor_id?: string;
  title: string;
  description?: string;
  zoom_link?: string;
  zoom_password?: string;
  start_time: string;
  duration_minutes: number;
  is_cancelled: boolean;
  created_at: string;
}

// ─── Review ───────────────────────────────────────────────
export interface Review {
  id: number;
  student_id: string;
  course_id: number;
  rating: number;
  comment?: string;
  is_approved: boolean;
  created_at: string;
  student?: Profile;
}

// ─── Notification ─────────────────────────────────────────
export interface Notification {
  id: number;
  user_id: string;
  type: NotificationType;
  title: string;
  body?: string;
  payload?: Record<string, unknown>;
  is_read: boolean;
  read_at?: string;
  created_at: string;
}

// ─── Message ──────────────────────────────────────────────
export interface Message {
  id: number;
  sender_id: string;
  receiver_id: string;
  subject?: string;
  body: string;
  is_read: boolean;
  read_at?: string;
  created_at: string;
  sender?: Profile;
  receiver?: Profile;
}

// ─── Announcement ─────────────────────────────────────────
export interface Announcement {
  id: number;
  title: string;
  body: string;
  course_id?: number;
  created_by?: string;
  is_active: boolean;
  created_at: string;
}

// ─── Settings ─────────────────────────────────────────────
export interface Setting {
  key: string;
  value: unknown;
  updated_at: string;
}

// ─── Homepage Section ─────────────────────────────────────
export interface HomepageSection {
  id: number;
  section_key: string;
  title?: string;
  content: Record<string, unknown>;
  is_visible: boolean;
  sort_order: number;
  updated_at: string;
}

// ─── Policy Page ──────────────────────────────────────────
export interface PolicyPage {
  id: number;
  slug: string;
  title: string;
  content: string;
  updated_at: string;
}

// ─── Integration ──────────────────────────────────────────
export interface Integration {
  id: number;
  service_name: string;
  config: Record<string, unknown>;
  is_connected: boolean;
  tested_at?: string;
  created_at: string;
  updated_at: string;
}

// ─── Audit Log ────────────────────────────────────────────
export interface AuditLog {
  id: number;
  user_id?: string;
  action: string;
  resource_type?: string;
  resource_id?: string;
  details?: Record<string, unknown>;
  ip_address?: string;
  created_at: string;
}
