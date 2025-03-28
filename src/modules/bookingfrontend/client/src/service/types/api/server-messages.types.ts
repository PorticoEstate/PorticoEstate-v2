export interface IServerMessage {
	type: "error" | 'success';
	text: string;
	title?: string;
	class: string; // unused in new impl
	id: string;
}