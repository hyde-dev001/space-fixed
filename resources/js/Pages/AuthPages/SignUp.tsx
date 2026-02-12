import { Head } from "@inertiajs/react";
import AuthLayout from "./AuthPageLayout";
import SignUpForm from "../../components/auth/SignUpForm";

interface Props {
  // Add props from Laravel controller later
}

export default function SignUp() {
  return (
    <>
      <Head title="Sign Up" />
      <AuthLayout>
        <SignUpForm />
      </AuthLayout>
    </>
  );
}
