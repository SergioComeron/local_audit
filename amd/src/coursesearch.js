/**
 * Transport AMD para core/form-autocomplete — cursos matriculados de un alumno.
 *
 * En la primera llamada carga todos los cursos del usuario seleccionado
 * y los almacena en caché. Las llamadas siguientes filtran localmente
 * sin volver al servidor (salvo que cambie el usuario).
 *
 * @module local_audit/coursesearch
 */
define([], function () {

    /** Caché: userId → array de {id, fullname, shortname} */
    var cache = {};

    return {
        /**
         * @param {string} selector  Selector CSS del <select> original.
         * @param {string} query     Texto tecleado por el usuario.
         * @param {Function} callback  Llamar con [{value, label}].
         * @param {Function} failure   Llamar en caso de error.
         */
        transport: function (selector, query, callback, failure) {
            var userEl = document.getElementById('userid');
            var userId = userEl ? userEl.value : '';

            if (!userId) {
                callback([]);
                return;
            }

            var doFilter = function (courses) {
                var lq = query.toLowerCase();
                var results = courses
                    .filter(function (c) {
                        return !lq ||
                            c.fullname.toLowerCase().indexOf(lq)   !== -1 ||
                            c.shortname.toLowerCase().indexOf(lq)  !== -1;
                    })
                    .map(function (c) {
                        return {
                            value: String(c.id),
                            label: c.fullname + ' [' + c.shortname + ']'
                        };
                    });
                callback(results);
            };

            if (cache[userId]) {
                doFilter(cache[userId]);
                return;
            }

            fetch(M.cfg.wwwroot + '/local/audit/ajax.php?type=course&userid=' + encodeURIComponent(userId))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    cache[userId] = data;
                    doFilter(data);
                })
                .catch(failure);
        },

        /**
         * Transforma la respuesta filtrada al formato {value, label}
         * que espera core/form-autocomplete.
         *
         * @param {string} selector
         * @param {Array}  results   Ya filtrados por doFilter (llevan value y label).
         * @return {Array}
         */
        processResults: function (selector, results) {
            return results;
        }
    };
});
