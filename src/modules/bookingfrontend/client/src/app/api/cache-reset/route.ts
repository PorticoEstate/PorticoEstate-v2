import { NextRequest, NextResponse } from 'next/server';
import { revalidatePath, revalidateTag } from 'next/cache';

export async function GET(request: NextRequest) {
	try {
		const { searchParams } = new URL(request.url);
		const path = searchParams.get('path');
		const tag = searchParams.get('tag');
		const all = searchParams.get('all');

		if (all === 'true') {
			// Force revalidation of all cached data
			revalidatePath('/', 'layout');
			return NextResponse.json({
				message: 'All caches cleared successfully',
				cleared: 'all'
			});
		}

		if (path) {
			revalidatePath(path);
			return NextResponse.json({
				message: `Cache cleared for path: ${path}`,
				cleared: 'path',
				path
			});
		}

		if (tag) {
			revalidateTag(tag);
			return NextResponse.json({
				message: `Cache cleared for tag: ${tag}`,
				cleared: 'tag',
				tag
			});
		}

		// Default: clear all caches
		revalidatePath('/', 'layout');
		return NextResponse.json({
			message: 'All caches cleared successfully',
			cleared: 'all'
		});

	} catch (error) {
		console.error('Cache reset error:', error);
		return NextResponse.json(
			{ error: 'Failed to reset cache' },
			{ status: 500 }
		);
	}
}