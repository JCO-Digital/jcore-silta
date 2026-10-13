/**
 * Data the PHP side passes in `window.jcoreSilta`.
 */
const data = window.jcoreSilta || {};

/** This site: uuid, home, uploads, name, rest, protocol, version. */
export const site = data.site || {};

/** Whether the user may manage connections and settings. */
export const canManage = !! data.canManage;

/** Selection passed in from a list table, the editor panel or History. */
export const preselect = {
	posts: [],
	direction: 'push',
	connection: '',
	job: 0,
	diff: false,
	...( data.preselect || {} ),
};
