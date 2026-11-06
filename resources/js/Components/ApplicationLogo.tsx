import { SVGAttributes } from 'react';

export default function ApplicationLogo(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect width="48" height="48" rx="12" fill="#0f766e" />
            <path
                d="M14 15a3 3 0 0 1 3-3h14a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3H22l-6 6v-6.2A3 3 0 0 1 14 27V15Z"
                fill="#fbbf24"
            />
        </svg>
    );
}
