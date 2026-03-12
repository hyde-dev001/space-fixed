import { Head } from "@inertiajs/react";
import AuthLayout from "./AuthPageLayout";
import SignInForm from "../../components/auth/SignInForm";

interface Props {
  // Add props from Laravel controller later
}

export default function SignIn() {
  return (
    <>
      <Head title="Sign In" />
      <AuthLayout>
        <SignInForm />
      </AuthLayout>
    </>
  );
}
