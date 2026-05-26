export interface IServerMessage {
	type: "error" | 'success';
	text: string;
	title?: string;
	translatable?: boolean;
	class: string; // unused in new impl
	id: string;
}