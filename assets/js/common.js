const bk_postview_main = {
    time_delay: 8000,
    end_point: null,
    post_id: null,
    _wpnonce: null,
    init:function(){
        this.end_point = bbk_post_view.api_public;
        this.post_id = bbk_post_view.post_id;
        this._wpnonce = bbk_post_view.nonce;
        
        setTimeout(()=>{
            bk_postview_main.setPostView();
        }, this.time_delay);
    },
    setPostView:function(){
        const url = `${this.end_point}/set-post-views`;
        const post_id = this.post_id;
        const _wpnonce = this._wpnonce;
        const data =  {post_id, _wpnonce};

        const settings = {
            method: 'POST',
            body: JSON.stringify(data),
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            }
        };

        try {
            const fetchResponse = fetch( url , settings);
            const result = fetchResponse.json();
            // return result;
        } catch (e) {
            // return e;
        }
        
    }
}

window.addEventListener("load", bk_postview_main.init());