import { useEffect, ComponentType } from 'react';
import { useRouter } from 'next/router';
import { useAuth } from '../hooks/useAuth';
import type { UserRole } from '../../../../packages/utils/types';

/**
 * HOC that checks user role — redirects to 403 if wrong role.
 */
export function withRole<P extends object>(
  WrappedComponent: ComponentType<P>,
  allowedRoles: UserRole[]
) {
  const RoleProtectedComponent = (props: P) => {
    const { user, profile, loading } = useAuth();
    const router = useRouter();

    useEffect(() => {
      if (!loading && !user) {
        router.replace(`/auth/login?redirect=${encodeURIComponent(router.asPath)}`);
        return;
      }
      if (!loading && profile && !allowedRoles.includes(profile.role)) {
        router.replace('/403');
      }
    }, [user, profile, loading, router]);

    if (loading) {
      return (
        <div className="min-h-screen flex items-center justify-center bg-white dark:bg-gray-900">
          <div className="text-center">
            <div className="inline-block w-12 h-12 border-4 border-primary border-t-transparent rounded-full animate-spin mb-4" />
            <p className="text-gray-600 dark:text-gray-300 text-lg">جاري التحقق من الصلاحيات...</p>
          </div>
        </div>
      );
    }

    if (!user || !profile || !allowedRoles.includes(profile.role)) {
      return null;
    }

    return <WrappedComponent {...props} />;
  };

  RoleProtectedComponent.displayName = `withRole(${WrappedComponent.displayName || WrappedComponent.name || 'Component'})`;

  return RoleProtectedComponent;
}
