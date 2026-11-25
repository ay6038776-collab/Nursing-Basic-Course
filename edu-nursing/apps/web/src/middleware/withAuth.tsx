import { useEffect, ComponentType } from 'react';
import { useRouter } from 'next/router';
import { useAuth } from '../hooks/useAuth';

/**
 * HOC that protects pages — redirects to login if not authenticated.
 */
export function withAuth<P extends object>(WrappedComponent: ComponentType<P>) {
  const AuthenticatedComponent = (props: P) => {
    const { user, loading } = useAuth();
    const router = useRouter();

    useEffect(() => {
      if (!loading && !user) {
        router.replace(`/auth/login?redirect=${encodeURIComponent(router.asPath)}`);
      }
    }, [user, loading, router]);

    if (loading) {
      return (
        <div className="min-h-screen flex items-center justify-center bg-white dark:bg-gray-900">
          <div className="text-center">
            <div className="inline-block w-12 h-12 border-4 border-primary border-t-transparent rounded-full animate-spin mb-4" />
            <p className="text-gray-600 dark:text-gray-300 text-lg">جاري التحميل...</p>
          </div>
        </div>
      );
    }

    if (!user) {
      return null;
    }

    return <WrappedComponent {...props} />;
  };

  AuthenticatedComponent.displayName = `withAuth(${WrappedComponent.displayName || WrappedComponent.name || 'Component'})`;

  return AuthenticatedComponent;
}
