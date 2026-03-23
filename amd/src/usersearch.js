/**
 * Transport AMD para core/form-autocomplete — búsqueda de usuarios.
 *
 * Busca usuarios por nombre, apellidos o nombre de usuario llamando
 * al endpoint local /local/audit/ajax.php.
 *
 * @module local_audit/usersearch
 */
define([], function () {

    return {
        /**
         * @param {string} selector  Selector CSS del <select> original.
         * @param {string} query     Texto tecleado por el usuario.
         * @param {Function} callback  Llamar con [{value, label}].
         * @param {Function} failure   Llamar en caso de error.
         */
        transport: function (selector, query, callback, failure) {
            if (query.length < 2) {
                callback([]);
                return;
            }

            fetch(M.cfg.wwwroot + '/local/audit/ajax.php?type=user&q=' + encodeURIComponent(query))
                .then(function (r) { return r.json(); })
                .then(callback)
                .catch(failure);
        },

        /**
         * Transforma la respuesta del servidor al formato {value, label}
         * que espera core/form-autocomplete.
         *
         * @param {string} selector
         * @param {Array}  results   Respuesta cruda de ajax.php.
         * @return {Array}           [{value, label}]
         */
        processResults: function (selector, results) {
            return results.map(function (item) {
                return {value: String(item.id), label: item.label};
            });
        }
    };
});
