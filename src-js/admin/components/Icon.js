/**
 * Icônes au trait (tracés Lucide, licence ISC).
 */
const PATHS = {
	layers: (
		<>
			<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z" />
			<path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65" />
			<path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65" />
		</>
	),
	list: (
		<>
			<path d="M3 12h.01M3 18h.01M3 6h.01M8 12h13M8 18h13M8 6h13" />
		</>
	),
	settings: (
		<>
			<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" />
			<circle cx="12" cy="12" r="3" />
		</>
	),
	history: (
		<>
			<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
			<path d="M3 3v5h5M12 7v5l4 2" />
		</>
	),
	columns: (
		<>
			<rect width="18" height="18" x="3" y="3" rx="2" />
			<path d="M12 3v18" />
		</>
	),
	external: (
		<>
			<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
		</>
	),
	x: <path d="M18 6 6 18M6 6l12 12" />,
	monitor: (
		<>
			<rect width="20" height="14" x="2" y="3" rx="2" />
			<path d="M8 21h8M12 17v4" />
		</>
	),
	tablet: (
		<>
			<rect width="16" height="20" x="4" y="2" rx="2" />
			<path d="M12 18h.01" />
		</>
	),
	phone: (
		<>
			<rect width="14" height="20" x="5" y="2" rx="2" />
			<path d="M12 18h.01" />
		</>
	),
	link: (
		<>
			<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
			<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
		</>
	),
	lock: (
		<>
			<rect width="18" height="11" x="3" y="11" rx="2" />
			<path d="M7 11V7a5 5 0 0 1 10 0v4" />
		</>
	),
};

export default function Icon( { name, size = 18 } ) {
	return (
		<svg
			className="lmv-icon"
			width={ size }
			height={ size }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden="true"
			focusable="false"
		>
			{ PATHS[ name ] }
		</svg>
	);
}
