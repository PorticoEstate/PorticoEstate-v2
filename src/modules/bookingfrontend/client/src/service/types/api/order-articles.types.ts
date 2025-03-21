/**
 * A single article order
 */
export interface ArticleOrder {
	/** Article mapping ID */
	id: number;

	/** Quantity ordered */
	quantity: number;

	/** Optional parent mapping ID for sub-items */
	parent_id?: number | null;
}




/**
 * Interface for an individual article in the booking system
 */
export interface IArticle {
	/** Unique identifier for the article mapping */
	id: number;

	/** Concatenated ID of article category and article ID */
	article_id: string;

	/** ID of the resource this article represents (if applicable) */
	resource_id?: number;

	/** ID of the parent article for sub-items */
	parent_mapping_id?: number | null;

	/** Name of the article */
	name: string;

	/** Unit of measurement (e.g., hour, each, kg) */
	unit: string;

	/** Tax code reference */
	tax_code: number;

	/** Tax percentage applied to this article */
	tax_percent: number;

	/** Group ID for categorizing articles */
	group_id: number;

	/** Additional description or remarks about the article */
	article_remark: string;

	/** Name of the article group */
	article_group_name: string;

	/** Remarks about the article group */
	article_group_remark?: string;

	/** Price before tax */
	ex_tax_price: string;

	/** Tax amount */
	tax: string;

	/** Price including tax */
	price: string;

	/** Additional remarks about pricing */
	price_remark: string;

	/** Unit price (typically same as price) */
	unit_price: string;

	/** Selected quantity by the user */
	selected_quantity: number | string;

	/** Total sum for selected quantity */
	selected_sum: string;

	/** Formatted string containing article selection data */
	selected_article_quantity: string;

	/** Whether the article is mandatory (1) or optional */
	mandatory: number | string;

	/** Localized unit name */
	lang_unit: string;
}
