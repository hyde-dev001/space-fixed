import { route } from 'ziggy-js';
import EmployeeMfaChallenge from '../../ERP/EmployeeMfaChallenge';

interface Props {
    email?: string;
    verifyRoute?: string;
    loginRoute?: string;
}

export default function ShopOwnerTwoFactor({ email, verifyRoute, loginRoute }: Props) {
    const userSideAuthClasses = ['userside-auth-page', 'userside-auth-card', 'userside-auth-primary'].join(' ');
    return (
        <div className={userSideAuthClasses}>
            <EmployeeMfaChallenge
                companyAccount={email ?? 'Shop Owner'}
                verifyRoute={verifyRoute ?? route('shop-owner.two-factor.verify')}
                loginRoute={loginRoute ?? route('login')}
            />
        </div>
    );
}
