// src/app/api/version/route.ts
import fs from 'fs';
import path from 'path';
import { NextRequest, NextResponse } from 'next/server';

export async function GET(request: NextRequest) {
	try {
		const commitIdPath = path.join(process.cwd(), '.git-commit-id');
		const buildTimePath = path.join(process.cwd(), '.build-time');

		const commitId = fs.existsSync(commitIdPath)
			? fs.readFileSync(commitIdPath, 'utf8').trim()
			: 'Unknown';

		const buildTime = fs.existsSync(buildTimePath)
			? fs.readFileSync(buildTimePath, 'utf8').trim()
			: 'Unknown';

		return NextResponse.json({
			commitId,
			buildTime
		});
	} catch (error) {
		return NextResponse.json(
			{ error: 'Failed to retrieve version information' },
			{ status: 500 }
		);
	}
}