!function(e){var t={};function n(a){if(t[a])return t[a].exports;var r=t[a]={i:a,l:!1,exports:{}};return e[a].call(r.exports,r,r.exports,n),r.l=!0,r.exports}n.m=e,n.c=t,n.d=function(e,t,a){n.o(e,t)||Object.defineProperty(e,t,{enumerable:!0,get:a})},n.r=function(e){"undefined"!=typeof Symbol&&Symbol.toStringTag&&Object.defineProperty(e,Symbol.toStringTag,{value:"Module"}),Object.defineProperty(e,"__esModule",{value:!0})},n.t=function(e,t){if(1&t&&(e=n(e)),8&t)return e;if(4&t&&"object"==typeof e&&e&&e.__esModule)return e;var a=Object.create(null);if(n.r(a),Object.defineProperty(a,"default",{enumerable:!0,value:e}),2&t&&"string"!=typeof e)for(var r in e)n.d(a,r,function(t){return e[t]}.bind(null,r));return a},n.n=function(e){var t=e&&e.__esModule?function(){return e.default}:function(){return e};return n.d(t,"a",t),t},n.o=function(e,t){return Object.prototype.hasOwnProperty.call(e,t)},n.p="/bundles/administration/",n(n.s="tGsh")}({"4bFi":function(e,t,n){var a=n("Z46q");"string"==typeof a&&(a=[[e.i,a,""]]),a.locals&&(e.exports=a.locals);(0,n("SZ7m").default)("4698af94",a,!0,{})},JSnI:function(e,t,n){var a=n("Sk/D");"string"==typeof a&&(a=[[e.i,a,""]]),a.locals&&(e.exports=a.locals);(0,n("SZ7m").default)("31cea28d",a,!0,{})},SZ7m:function(e,t,n){"use strict";function a(e,t){for(var n=[],a={},r=0;r<t.length;r++){var o=t[r],i=o[0],s={id:e+":"+r,css:o[1],media:o[2],sourceMap:o[3]};a[i]?a[i].parts.push(s):n.push(a[i]={id:i,parts:[s]})}return n}n.r(t),n.d(t,"default",(function(){return m}));var r="undefined"!=typeof document;if("undefined"!=typeof DEBUG&&DEBUG&&!r)throw new Error("vue-style-loader cannot be used in a non-browser environment. Use { target: 'node' } in your Webpack config to indicate a server-rendering environment.");var o={},i=r&&(document.head||document.getElementsByTagName("head")[0]),s=null,u=0,l=!1,c=function(){},p=null,f="data-vue-ssr-id",d="undefined"!=typeof navigator&&/msie [6-9]\b/.test(navigator.userAgent.toLowerCase());function m(e,t,n,r){l=n,p=r||{};var i=a(e,t);return h(i),function(t){for(var n=[],r=0;r<i.length;r++){var s=i[r];(u=o[s.id]).refs--,n.push(u)}t?h(i=a(e,t)):i=[];for(r=0;r<n.length;r++){var u;if(0===(u=n[r]).refs){for(var l=0;l<u.parts.length;l++)u.parts[l]();delete o[u.id]}}}}function h(e){for(var t=0;t<e.length;t++){var n=e[t],a=o[n.id];if(a){a.refs++;for(var r=0;r<a.parts.length;r++)a.parts[r](n.parts[r]);for(;r<n.parts.length;r++)a.parts.push(g(n.parts[r]));a.parts.length>n.parts.length&&(a.parts.length=n.parts.length)}else{var i=[];for(r=0;r<n.parts.length;r++)i.push(g(n.parts[r]));o[n.id]={id:n.id,refs:1,parts:i}}}}function y(){var e=document.createElement("style");return e.type="text/css",i.appendChild(e),e}function g(e){var t,n,a=document.querySelector("style["+f+'~="'+e.id+'"]');if(a){if(l)return c;a.parentNode.removeChild(a)}if(d){var r=u++;a=s||(s=y()),t=S.bind(null,a,r,!1),n=S.bind(null,a,r,!0)}else a=y(),t=w.bind(null,a),n=function(){a.parentNode.removeChild(a)};return t(e),function(a){if(a){if(a.css===e.css&&a.media===e.media&&a.sourceMap===e.sourceMap)return;t(e=a)}else n()}}var v,b=(v=[],function(e,t){return v[e]=t,v.filter(Boolean).join("\n")});function S(e,t,n,a){var r=n?"":a.css;if(e.styleSheet)e.styleSheet.cssText=b(t,r);else{var o=document.createTextNode(r),i=e.childNodes;i[t]&&e.removeChild(i[t]),i.length?e.insertBefore(o,i[t]):e.appendChild(o)}}function w(e,t){var n=t.css,a=t.media,r=t.sourceMap;if(a&&e.setAttribute("media",a),p.ssrId&&e.setAttribute(f,t.id),r&&(n+="\n/*# sourceURL="+r.sources[0]+" */",n+="\n/*# sourceMappingURL=data:application/json;base64,"+btoa(unescape(encodeURIComponent(JSON.stringify(r))))+" */"),e.styleSheet)e.styleSheet.cssText=n;else{for(;e.firstChild;)e.removeChild(e.firstChild);e.appendChild(document.createTextNode(n))}}},"Sk/D":function(e,t,n){},T8Cy:function(e){e.exports=JSON.parse('{"multisafepay-verify-api-key":{"success":"Connection was successfully established","error":"Connection could not be established. Please check your API credentials","apiButton":"Validate API credentials"},"msp-support":{"documentation":"Documentation","api_documentation":"API Documentation","read_docs":"Read our documentation for more information about MultiSafepay and how to get started","manual":"Manual","changelog":"Changelog","faq":"FAQ","for_developers":"For developers","account":"Account","e-mail":"E-mail","telephone":"Telephone","contact":"Contact","multisafepay_account_needed":"To use this plugin you need a MultiSafepay account.","multisafepay_test_account":"If you would like to have a clear overview of what MultiSafepay has to offer, feel free to create a <a href=\\"https://testmerchant.multisafepay.com/signup\\" target=\\"_blank\\" rel=\\"noopener\\">test account</a>.","multisafepay_assistance_integration_team":"Need assistance? Feel free to contact our integration team:","multisafepay_live_account":"If you would like to set up a live account, feel free to create a <a href=\\"https://merchant.multisafepay.com/signup\\" target=\\"_blank\\" rel=\\"noopener\\">live account</a> or contact the MultiSafepay Sales department:"}}')},Z46q:function(e,t,n){},a9DH:function(e,t){e.exports='<div>\n    <sw-button-process\n        :isLoading="isLoading"\n        @click="check"\n    >{{ $tc(\'multisafepay-verify-api-key.apiButton\') }}</sw-button-process>\n</div>\n'},cFGp:function(e,t){e.exports='<template>\n    <sw-card title="Refund"\n             v-show="isRefundAllowed"\n             :isLoading="isLoading"\n             class="sw-order-detail-base__line-item-grid-card">\n        <sw-number-field type="number" label="Amount" v-model="amount"\n                         placeholder="0.00" numberType="float" step="0.01"\n                         :max="maxRefundableAmount" :disabled="isRefundDisabled"/>\n        <sw-button variant="primary" @click="showRefundModal()" :disabled="isRefundDisabled">Refund</sw-button>\n        <span class="float-right">\n            <strong>Amount refunded: {{ order ? order.currency.symbol : \'currency\' }}&nbsp;{{ refundedAmount }}</strong>\n        </span>\n        <sw-modal v-show="showModal" title="MultiSafepay refund" variant="small" @modal-close="closeModal()">\n            Are you sure you want to refund {{ order ? order.currency.symbol : \'currency\' }}{{ this.amount }}?\n            <template slot="modal-footer">\n\n                <sw-button @click="closeModal()" size="small">\n                    {{ $tc(\'global.default.cancel\') }}\n                </sw-button>\n\n                <sw-button @click="applyRefund()"\n                           size="small"\n                           variant="primary">\n                    {{ $tc(\'global.default.apply\') }}\n                </sw-button>\n\n            </template>\n        </sw-modal>\n    </sw-card>\n</template>\n'},fM8c:function(e,t){e.exports='<template>\n    <div id="multisafepay-support">\n        <h2 class="multisafepay-title">{{ $tc("msp-support.documentation") }}</h2>\n        <p>{{ $tc("msp-support.read_docs") }}:</p>\n        <ul class="multisafepay-ul">\n            <li>\n                <a href="https://docs.multisafepay.com/shopware-6/" target="_blank" rel="noopener">\n                    {{ $tc(\'msp-support.manual\') }}\n                </a>\n            </li>\n            <li>\n                <a href="https://github.com/MultiSafepay/shopware6/blob/master/CHANGELOG.md"\n                   target="_blank" rel="noopener">\n                    {{ $tc("msp-support.changelog") }}\n                </a>\n            </li>\n            <li>\n                <a href="https://docs.multisafepay.com/integration/ready-made/shopware6/faq/" target="_blank" rel="noopener">\n                    {{ $tc("msp-support.faq") }}\n                </a>\n            </li>\n        </ul>\n        <p class="mt-1">{{ $tc("msp-support.for_developers") }}:</p>\n        <ul class="multisafepay-ul">\n            <li>\n                <a href="https://docs.multisafepay.com/api/" target="_blank" rel="noopener">\n                    {{ $tc("msp-support.api_documentation") }}\n                </a>\n            </li>\n            <li>\n                <a href="https://github.com/MultiSafepay/Shopware6" target="_blank" rel="noopener">\n                    MultiSafepay Github\n                </a>\n            </li>\n        </ul>\n        <h2 class="mt-2">{{ $tc("msp-support.account")}}</h2>\n        <p>\n            {{ $tc("msp-support.multisafepay_account_needed") }}\n            <br/>\n            <span v-html=\'$tc("msp-support.multisafepay_test_account")\'></span>\n        </p>\n        <p class="mt-2">\n            <span v-html=\'$tc("msp-support.multisafepay_live_account")\'></span>\n        </p>\n        <ul class="multisafepay-ul-none">\n            <li>\n                {{ $tc("msp-support.telephone") }}:\n                <a href="tel:+31208500501">\n                    +31 (0)20 - 8500501\n                </a>\n            </li>\n            <li>\n                {{ $tc("msp-support.e-mail") }}:\n                <a href="mailto:sales@multisafepay.com">\n                    sales@multisafepay.com\n                </a>\n            </li>\n        </ul>\n        <h2 class="mt-2">{{ $tc("msp-support.contact") }}</h2>\n        <p>\n            {{ $tc("msp-support.multisafepay_assistance_integration_team") }}\n        </p>\n        <ul class="multisafepay-ul-none">\n            <li>\n                {{ $tc("msp-support.telephone") }}:\n                <a href="tel:+31208500500">\n                    +31 (0)20 - 8500500\n                </a>\n            </li>\n            <li>\n                {{ $tc("msp-support.e-mail") }}:\n                <a href="mailto:integration@multisafepay.com">\n                    integration@multisafepay.com\n                </a>\n            </li>\n        </ul>\n    </div>\n\n</template>\n'},hvNS:function(e,t){e.exports='{% block sw_order_detail_base_line_items_card %}\n    {% parent %}\n\n    <multisafepay-refund :orderId="orderId"/>\n{% endblock %}\n'},m32b:function(e){e.exports=JSON.parse('{"multisafepay-verify-api-key":{"success":"Die Verbindung wurde erfolgreich hergestellt","error":"Verbindung konnte nicht hergestellt werden. Bitte überprüfen Sie Ihre API-Anmeldeinformationen","apiButton":"API Verbindung testen"},"msp-support":{"documentation":"Dokumentation","api_documentation":"API Dokumentation","read_docs":"Lesen Sie in unserem Dokumentationen mehr über MultiSafepay und wie Sie mit uns starten können","manual":"Anleitung","changelog":"Änderungsprotokoll","faq":"FAQ","for_developers":"Für Entwickler","account":"Account","e-mail":"E-Mail","telephone":"Telefon","contact":"Kontakt","multisafepay_account_needed":"Um dieses Plugin nutzen zu können benötigen Sie einen MultiSafepay-Account.","multisafepay_test_account":"Wenn Sie einen genauen Überblick über die Services von MultiSafepay bekommen möchten, können Sie einfach einen <a href=\\"https://testmerchant.multisafepay.com/signup\\" target=\\"_blank\\" rel=\\"noopener\\">Test-Account</a> eröffnen.","multisafepay_assistance_integration_team":"Sie brauchen Hilfe? Kontaktieren Sie unser Integrations-Team:","multisafepay_live_account":"Wenn Sie einen Account eröffnen wollen, können Sie einfach einen <a href=\\"https://merchant.multisafepay.com/signup\\" target=\\"_blank\\" rel=\\"noopener\\">Live-Account</a> eröffnen oder kontaktieren Sie das MultiSafepay Sales-Department:"}}')},tGsh:function(e,t,n){"use strict";n.r(t);n("JSnI");var a=n("cFGp"),r=n.n(a),o=Shopware,i=o.Component,s=o.Mixin,u=Shopware.Data.Criteria;i.register("multisafepay-refund",{template:r.a,inject:["repositoryFactory","orderService","stateStyleDataProviderService","multiSafepayApiService"],mixins:[s.getByName("notification")],props:{orderId:{type:String,required:!0}},data:function(){return{amount:null,isLoading:null,versionContext:null,order:null,maxRefundableAmount:0,isRefundAllowed:!0,refundedAmount:0,showModal:!1,isRefundDisabled:!1}},watch:{orderId:function(){this.createdComponent()},amount:function(){this.amount=parseFloat(this.amount).toFixed(2)},refundedAmount:function(){this.refundedAmount=parseFloat(this.refundedAmount).toFixed(2)}},methods:{closeModal:function(){this.showModal=!1},showRefundModal:function(){this.amount<.01?this.createNotificationWarning({title:"Invalid amount",message:"Fill in a valid amount"}):this.showModal=!0},applyRefund:function(){var e=this;this.closeModal(),this.multiSafepayApiService.refund(this.amount,this.orderId).then((function(t){!1!==t.status?(e.createNotificationSuccess({title:"Success",message:"Successfully refunded"}),e.reloadEntityData()):e.createNotificationError({title:"Failed to refund",message:t.message})}))},createdComponent:function(){this.versionContext=Shopware.Context.api,this.reloadEntityData()},reloadEntityData:function(){var e=this;return this.isLoading=!0,this.orderRepository.get(this.orderId,this.versionContext,this.orderCriteria).then((function(t){return e.order=t,e.multiSafepayApiService.getRefundData(e.order.id).then((function(t){e.isRefundAllowed=t.isAllowed,e.refundedAmount=t.refundedAmount,e.maxRefundableAmount=e.order.amountTotal-e.refundedAmount,e.isRefundDisabled=e.order.amountTotal-e.refundedAmount==0,e.isLoading=!1})).catch((function(){e.isRefundAllowed=!1})),Promise.resolve()})).catch((function(){return Promise.reject()}))}},computed:{orderRepository:function(){return this.repositoryFactory.create("order")},orderCriteria:function(){var e=new u(this.page,this.limit);return e.addAssociation("currency"),e}},created:function(){this.createdComponent()}});var l=n("a9DH"),c=n.n(l),p=Shopware,f=p.Component,d=p.Mixin;f.register("multisafepay-verify-api-key",{template:c.a,inject:["multiSafepayApiService"],mixins:[d.getByName("notification")],data:function(){return{isLoading:!1}},computed:{globalPluginConfig:function(){var e=this.$parent.$parent.$parent.actualConfigData;return e?e.null:this.$parent.$parent.$parent.$parent.actualConfigData.null},actualPluginConfig:function(){var e=this.$parent.$parent.$parent.currentSalesChannelId;return void 0!==e?this.$parent.$parent.$parent.actualConfigData[e]:(e=this.$parent.$parent.$parent.$parent.currentSalesChannelId,this.$parent.$parent.$parent.$parent.actualConfigData[e])}},methods:{check:function(){var e=this;this.isLoading=!0,this.multiSafepayApiService.verifyApiKey(this.globalPluginConfig,this.actualPluginConfig).then((function(t){if(!1===t.success)return e.createNotificationWarning({title:"MultiSafepay",message:e.$tc("multisafepay-verify-api-key.error")}),void(e.isLoading=!1);e.createNotificationSuccess({title:"MultiSafepay",message:e.$tc("multisafepay-verify-api-key.success")}),e.isLoading=!1}))}}});n("4bFi");var m=n("fM8c"),h=n.n(m);Shopware.Component.register("multisafepay-support",{template:h.a});var y=n("hvNS"),g=n.n(y);function v(e){return(v="function"==typeof Symbol&&"symbol"==typeof Symbol.iterator?function(e){return typeof e}:function(e){return e&&"function"==typeof Symbol&&e.constructor===Symbol&&e!==Symbol.prototype?"symbol":typeof e})(e)}function b(e,t){if(!(e instanceof t))throw new TypeError("Cannot call a class as a function")}function S(e,t){for(var n=0;n<t.length;n++){var a=t[n];a.enumerable=a.enumerable||!1,a.configurable=!0,"value"in a&&(a.writable=!0),Object.defineProperty(e,a.key,a)}}function w(e,t){return(w=Object.setPrototypeOf||function(e,t){return e.__proto__=t,e})(e,t)}function _(e){var t=function(){if("undefined"==typeof Reflect||!Reflect.construct)return!1;if(Reflect.construct.sham)return!1;if("function"==typeof Proxy)return!0;try{return Boolean.prototype.valueOf.call(Reflect.construct(Boolean,[],(function(){}))),!0}catch(e){return!1}}();return function(){var n,a=C(e);if(t){var r=C(this).constructor;n=Reflect.construct(a,arguments,r)}else n=a.apply(this,arguments);return A(this,n)}}function A(e,t){return!t||"object"!==v(t)&&"function"!=typeof t?function(e){if(void 0===e)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return e}(e):t}function C(e){return(C=Object.setPrototypeOf?Object.getPrototypeOf:function(e){return e.__proto__||Object.getPrototypeOf(e)})(e)}var k=Shopware.Classes.ApiService,$=function(e){!function(e,t){if("function"!=typeof t&&null!==t)throw new TypeError("Super expression must either be null or a function");e.prototype=Object.create(t&&t.prototype,{constructor:{value:e,writable:!0,configurable:!0}}),t&&w(e,t)}(o,e);var t,n,a,r=_(o);function o(e,t){var n=arguments.length>2&&void 0!==arguments[2]?arguments[2]:"multisafepay";return b(this,o),r.call(this,e,t,n)}return t=o,(n=[{key:"refund",value:function(e,t){var n="".concat(this.getApiBasePath(),"/refund");return this.httpClient.post(n,{amount:100*e,orderId:t},{headers:this.getBasicHeaders()}).then((function(e){return k.handleResponse(e)})).catch((function(e){return k.handleResponse(e)}))}},{key:"getRefundData",value:function(e){var t="".concat(this.getApiBasePath(),"/get-refund-data");return this.httpClient.post(t,{orderId:e},{headers:this.getBasicHeaders()}).then((function(e){return k.handleResponse(e)}))}},{key:"verifyApiKey",value:function(e,t){var n="".concat(this.getApiBasePath(),"/verify-api-key"),a=this.getBasicHeaders();return this.httpClient.post(n,{globalPluginConfig:e,actualPluginConfig:t},{headers:a}).then((function(e){return k.handleResponse(e)}))}}])&&S(t.prototype,n),a&&S(t,a),o}(k),M=n("m32b"),x=n("T8Cy"),R=Shopware,P=R.Component,D=R.Application;P.override("sw-order-detail-base",{template:g.a}),D.addServiceProvider("multiSafepayApiService",(function(e){var t=D.getContainer("init");return new $(t.httpClient,e.loginService)})),Shopware.Locale.extend("de-DE",M),Shopware.Locale.extend("en-GB",x)}});