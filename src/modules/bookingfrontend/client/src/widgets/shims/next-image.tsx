import React, {useCallback, useState} from 'react';

interface ImageProps extends React.ImgHTMLAttributes<HTMLImageElement> {
	src: string;
	alt?: string;
	fill?: boolean;
	sizes?: string;
	priority?: boolean;
}

const PLACEHOLDER = 'data:image/svg+xml,' + encodeURIComponent(
	'<svg xmlns="http://www.w3.org/2000/svg" width="400" height="200" viewBox="0 0 400 200">' +
	'<rect fill="#e5e7eb" width="400" height="200"/>' +
	'<text x="200" y="105" text-anchor="middle" fill="#9ca3af" font-family="sans-serif" font-size="14">No image</text>' +
	'</svg>'
);

const Image: React.FC<ImageProps> = ({src, alt, fill, sizes, priority, className, style, ...rest}) => {
	const [imgSrc, setImgSrc] = useState(src);
	const onError = useCallback(() => setImgSrc(PLACEHOLDER), []);

	return (
		<img
			src={imgSrc}
			alt={alt || ''}
			className={className}
			onError={onError}
			style={{
				...style,
				...(fill ? {position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover'} : {})
			}}
			{...rest}
		/>
	);
};

export default Image;
