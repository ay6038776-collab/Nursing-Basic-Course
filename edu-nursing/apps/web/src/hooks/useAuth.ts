import { useState, useEffect, useCallback } from 'react';
import { useRouter } from 'next/router';
import { supabase } from '../../../../packages/utils/supabase';
import type { Profile, UserRole } from '../../../../packages/utils/types';
import type { User, Session } from '@supabase/supabase-js';

interface SignUpProfile {
  full_name: string;
  phone?: string;
}

interface AuthState {
  user: User | null;
  profile: Profile | null;
  session: Session | null;
  loading: boolean;
  error: string | null;
}

export function useAuth() {
  const router = useRouter();
  const [state, setState] = useState<AuthState>({
    user: null,
    profile: null,
    session: null,
    loading: true,
    error: null,
  });

  const fetchProfile = useCallback(async (userId: string): Promise<Profile | null> => {
    const { data, error } = await supabase
      .from('profiles')
      .select('*')
      .eq('id', userId)
      .single();

    if (error) {
      console.error('Error fetching profile:', error.message);
      return null;
    }
    return data as Profile;
  }, []);

  useEffect(() => {
    let mounted = true;

    const initializeAuth = async () => {
      try {
        const { data: { session } } = await supabase.auth.getSession();

        if (session?.user && mounted) {
          const profile = await fetchProfile(session.user.id);
          setState({
            user: session.user,
            profile,
            session,
            loading: false,
            error: null,
          });
        } else if (mounted) {
          setState({
            user: null,
            profile: null,
            session: null,
            loading: false,
            error: null,
          });
        }
      } catch (err) {
        if (mounted) {
          setState(prev => ({ ...prev, loading: false, error: 'فشل في تحميل بيانات المستخدم' }));
        }
      }
    };

    initializeAuth();

    const { data: { subscription } } = supabase.auth.onAuthStateChange(
      async (event, session) => {
        if (!mounted) return;

        if (event === 'SIGNED_IN' && session?.user) {
          const profile = await fetchProfile(session.user.id);
          setState({
            user: session.user,
            profile,
            session,
            loading: false,
            error: null,
          });
        } else if (event === 'SIGNED_OUT') {
          setState({
            user: null,
            profile: null,
            session: null,
            loading: false,
            error: null,
          });
        } else if (event === 'TOKEN_REFRESHED' && session?.user) {
          setState(prev => ({ ...prev, session, user: session.user }));
        }
      }
    );

    return () => {
      mounted = false;
      subscription.unsubscribe();
    };
  }, [fetchProfile]);

  const signIn = useCallback(async (email: string, password: string) => {
    setState(prev => ({ ...prev, loading: true, error: null }));
    try {
      const { data, error } = await supabase.auth.signInWithPassword({ email, password });

      if (error) {
        let message = 'فشل في تسجيل الدخول';
        if (error.message.includes('Invalid login credentials')) {
          message = 'البريد الإلكتروني أو كلمة المرور غير صحيحة';
        } else if (error.message.includes('Email not confirmed')) {
          message = 'يرجى تأكيد بريدك الإلكتروني أولاً';
        }
        setState(prev => ({ ...prev, loading: false, error: message }));
        return { success: false, error: message };
      }

      if (data.user) {
        const profile = await fetchProfile(data.user.id);
        setState({
          user: data.user,
          profile,
          session: data.session,
          loading: false,
          error: null,
        });
        return { success: true, profile };
      }

      setState(prev => ({ ...prev, loading: false }));
      return { success: false, error: 'حدث خطأ غير متوقع' };
    } catch (err) {
      const message = 'حدث خطأ في الاتصال';
      setState(prev => ({ ...prev, loading: false, error: message }));
      return { success: false, error: message };
    }
  }, [fetchProfile]);

  const signUp = useCallback(async (email: string, password: string, profileData: SignUpProfile) => {
    setState(prev => ({ ...prev, loading: true, error: null }));
    try {
      const { data, error } = await supabase.auth.signUp({
        email,
        password,
        options: {
          data: {
            full_name: profileData.full_name,
            phone: profileData.phone || '',
          },
        },
      });

      if (error) {
        let message = 'فشل في إنشاء الحساب';
        if (error.message.includes('already registered')) {
          message = 'هذا البريد الإلكتروني مسجل بالفعل';
        } else if (error.message.includes('Password')) {
          message = 'كلمة المرور يجب أن تكون 6 أحرف على الأقل';
        }
        setState(prev => ({ ...prev, loading: false, error: message }));
        return { success: false, error: message };
      }

      if (data.user) {
        // Create profile record
        const { error: profileError } = await supabase.from('profiles').upsert({
          id: data.user.id,
          full_name: profileData.full_name,
          phone: profileData.phone || null,
          role: 'student' as UserRole,
          language: 'ar',
          theme: 'light',
          is_active: true,
        });

        if (profileError) {
          console.error('Profile creation error:', profileError.message);
        }

        setState(prev => ({ ...prev, loading: false, error: null }));
        return { success: true, user: data.user };
      }

      setState(prev => ({ ...prev, loading: false }));
      return { success: false, error: 'حدث خطأ غير متوقع' };
    } catch (err) {
      const message = 'حدث خطأ في الاتصال';
      setState(prev => ({ ...prev, loading: false, error: message }));
      return { success: false, error: message };
    }
  }, []);

  const signOut = useCallback(async () => {
    setState(prev => ({ ...prev, loading: true }));
    await supabase.auth.signOut();
    setState({
      user: null,
      profile: null,
      session: null,
      loading: false,
      error: null,
    });
    router.push('/auth/login');
  }, [router]);

  const resetPassword = useCallback(async (email: string) => {
    setState(prev => ({ ...prev, loading: true, error: null }));
    try {
      const { error } = await supabase.auth.resetPasswordForEmail(email, {
        redirectTo: `${window.location.origin}/auth/reset-password`,
      });

      if (error) {
        const message = 'فشل في إرسال رابط استعادة كلمة المرور';
        setState(prev => ({ ...prev, loading: false, error: message }));
        return { success: false, error: message };
      }

      setState(prev => ({ ...prev, loading: false }));
      return { success: true };
    } catch (err) {
      const message = 'حدث خطأ في الاتصال';
      setState(prev => ({ ...prev, loading: false, error: message }));
      return { success: false, error: message };
    }
  }, []);

  const refreshProfile = useCallback(async () => {
    if (state.user) {
      const profile = await fetchProfile(state.user.id);
      setState(prev => ({ ...prev, profile }));
    }
  }, [state.user, fetchProfile]);

  return {
    user: state.user,
    profile: state.profile,
    session: state.session,
    loading: state.loading,
    error: state.error,
    signIn,
    signUp,
    signOut,
    resetPassword,
    refreshProfile,
    isAdmin: state.profile?.role === 'super_admin',
    isLecturer: state.profile?.role === 'lecturer',
    isStudent: state.profile?.role === 'student',
  };
}
