export interface IServerMessage {
	type: "error" | 'success';
	text: string;
	class: string; // unused in new impl
	id: string;
}