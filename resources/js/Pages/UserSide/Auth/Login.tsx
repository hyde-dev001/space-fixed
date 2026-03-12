import { useEffect } from 'react';
import { router } from '@inertiajs/react';

export default function Login() {
  useEffect(() => {
    // Redirect to the new user login page
    router.visit('/user/login');
  }, []);

  return null;
}
