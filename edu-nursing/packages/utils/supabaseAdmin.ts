import { createClient } from '@supabase/supabase-js';

/**
 * Server‑side Supabase client using the SERVICE_ROLE key.
 * ⚠️  NEVER expose this in the browser / frontend bundle.
 */
const supabaseUrl = process.env.NEXT_PUBLIC_SUPABASE_URL!;
const serviceRoleKey = process.env.SUPABASE_SERVICE_ROLE_KEY!;

export const supabaseAdmin = createClient(supabaseUrl, serviceRoleKey, {
  auth: { autoRefreshToken: false, persistSession: false },
});
