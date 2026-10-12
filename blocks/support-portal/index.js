(function (wp) {
    if (!wp || !wp.blocks || !wp.element) {
        return;
    }

    var registerBlockType = wp.blocks.registerBlockType;
    var el = wp.element.createElement;
    var __ = wp.i18n.__;

    registerBlockType('wpcalibrate/support-portal', {
        edit: function (props) {
            return el(
                'div',
                {
                    className: 'wpcalibrate-block-editor-preview',
                    style: {
                        padding: '24px',
                        border: '2px dashed #0073aa',
                        borderRadius: '8px',
                        backgroundColor: '#f8fafc',
                        textAlign: 'center',
                        color: '#1e293b'
                    }
                },
                el('h3', { style: { margin: '0 0 8px 0' } }, __('WPCalibrate Support Portal', 'wpcalibrate-support-desk')),
                el('p', { style: { margin: '0', color: '#64748b' } }, __('The frontend support portal will be rendered here dynamically (ticket submission, ticket lists, and conversation views).', 'wpcalibrate-support-desk'))
            );
        },
        save: function () {
            return null; // Server-side rendered block
        }
    });
})(window.wp);
