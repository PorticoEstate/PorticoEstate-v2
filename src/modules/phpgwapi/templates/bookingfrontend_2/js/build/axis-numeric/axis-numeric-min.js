YUI.add("axis-numeric",function(t,i){var e=t.Lang;t.NumericAxis=t.Base.create("numericAxis",t.Axis,[t.NumericImpl],{_getLabelByIndex:function(t,i){var e,a=this.get("minimum"),s=this.get("maximum"),u=(s-a)/(i-1),r=this.get("roundingMethod");return i-=1,0===t?e=a:t===i?e=s:(e=t*u,"niceNumber"===r&&(e=this._roundToNearest(e,u)),e+=a),parseFloat(e)},_getLabelData:function(t,i,e,a,s,u,r,n,m){var o,h,c,g=[],x=[],d="x"===i,l=d?r+u:u;for(m=m||this._getDataValuesByCount(n,a,s),h=0;h<n;h+=1)(o=parseFloat(m[h]))<=s&&o>=a&&((c={})[i]=t,c[e]=this._getCoordFromValue(a,s,r,o,l,d),g.push(c),x.push(o));return{points:g,values:x}},_hasDataOverflow:function(){var t,i,a;return!(!this.get("setMin")&&!this.get("setMax"))||(t=this.get("roundingMethod"),i=this._actualMinimum,a=this._actualMaximum,!(!e.isNumber(t)||!(e.isNumber(a)&&a>this._dataMaximum||e.isNumber(i)&&i<this._dataMinimum)))}})},"patched-v3.18.1",{requires:["axis","axis-numeric-base"]});