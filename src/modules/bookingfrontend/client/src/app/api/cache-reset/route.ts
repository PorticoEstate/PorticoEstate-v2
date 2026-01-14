import { NextRequest, NextResponse } from 'next/server';
import { revalidatePath, revalidateTag } from 'next/cache';
import { promises as fs } from 'fs';
import { join } from 'path';

export async function GET(request: NextRequest) {
	try {
		const { searchParams } = new URL(request.url);
		const path = searchParams.get('path');
		const tag = searchParams.get('tag');
		const all = searchParams.get('all');

		if (all === 'true') {
			// Force revalidation of all cached data
			revalidatePath('/', 'layout');

			// Clear Next.js image optimization cache
			const imageCachePath = join(process.cwd(), '.next', 'cache', 'images');
			try {
				await fs.rm(imageCachePath, { recursive: true, force: true });
			} catch (error) {
				console.error('Failed to clear image cache:', error);
			}

			return NextResponse.json({
				message: 'All caches cleared successfully (including images)',
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

		// Clear Next.js image optimization cache
		const imageCachePath = join(process.cwd(), '.next', 'cache', 'images');
		try {
			await fs.rm(imageCachePath, { recursive: true, force: true });
		} catch (error) {
			console.error('Failed to clear image cache:', error);
		}

		return NextResponse.json({
			message: 'All caches cleared successfully (including images)',
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